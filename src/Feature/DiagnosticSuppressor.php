<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\SourceComment;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlCommentParser;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class DiagnosticSuppressor
{
    private const DIRECTIVE_PATTERN = '/@symfony-lsp-ignore\b[^\r\n]*/';
    private const VALID_DIRECTIVE_PATTERN = '/^@symfony-lsp-ignore[ \t]+(?<codes>[a-z][a-z0-9_.-]*(?:[ \t]*,[ \t]*[a-z][a-z0-9_.-]*)*)(?:[ \t]+\([^\r\n]*\))?[ \t]*$/D';

    public function __construct(
        private readonly PositionConverter $positions,
        private readonly LspProtocolMapper $protocol,
        private readonly DiagnosticCodeRegistry $diagnosticCodes,
        private readonly PhpCommentParserInterface $phpComments,
        private readonly TwigCommentParser $twigComments,
        private readonly YamlCommentParser $yamlComments,
        private readonly XmlCommentParser $xmlComments,
    ) {
    }

    /**
     * @param list<array<array-key, mixed>> $diagnostics
     *
     * @return list<array<array-key, mixed>>
     */
    public function suppress(Document $document, array $diagnostics): array
    {
        [$suppressed, $warnings] = $this->resolve($document, $diagnostics);
        $active = [];
        foreach ($diagnostics as $index => $diagnostic) {
            if (!isset($suppressed[$index])) {
                $active[] = $diagnostic;
            }
        }
        array_push($active, ...$warnings);

        return $active;
    }

    /**
     * @param list<CollectedDiagnostic> $diagnostics
     *
     * @return list<CollectedDiagnostic>
     */
    public function suppressCollected(Document $document, array $diagnostics): array
    {
        $protocolDiagnostics = [];
        foreach ($diagnostics as $diagnostic) {
            $protocolDiagnostics[] = $diagnostic->diagnostic;
        }
        [$suppressed, $warnings] = $this->resolve($document, $protocolDiagnostics);
        $active = [];
        foreach ($diagnostics as $index => $diagnostic) {
            if (!isset($suppressed[$index])) {
                $active[] = $diagnostic;
            }
        }
        foreach ($warnings as $warning) {
            $active[] = new CollectedDiagnostic('suppression', $warning);
        }

        return $active;
    }

    /**
     * @param list<array<array-key, mixed>> $diagnostics
     *
     * @return array{array<int, true>, list<array<array-key, mixed>>}
     */
    private function resolve(Document $document, array $diagnostics): array
    {
        [$suppressions, $warnings] = $this->suppressions($document);
        $candidates = [];
        foreach ($diagnostics as $index => $diagnostic) {
            $range = $diagnostic['range'] ?? null;
            $start = \is_array($range) ? ($range['start'] ?? null) : null;
            $line = \is_array($start) ? ($start['line'] ?? null) : null;
            $character = \is_array($start) ? ($start['character'] ?? null) : null;
            $code = $diagnostic['code'] ?? null;
            if (!\is_int($line) || !\is_int($character) || !\is_string($code)) {
                continue;
            }
            $candidates[$line][$code][] = ['index' => $index, 'character' => $character];
        }
        foreach ($candidates as &$byCode) {
            foreach ($byCode as &$items) {
                usort($items, static fn (array $left, array $right): int => [$left['character'], $left['index']] <=> [$right['character'], $right['index']]);
            }
        }
        unset($byCode, $items);

        $suppressed = [];
        foreach ($suppressions as $suppression) {
            foreach ($suppression->codes as $code) {
                if ([] === ($candidates[$suppression->targetLine][$code] ?? [])) {
                    continue;
                }
                $candidate = array_shift($candidates[$suppression->targetLine][$code]);
                $suppressed[$candidate['index']] = true;
            }
        }

        return [$suppressed, $warnings];
    }

    /** @return array{list<DiagnosticSuppression>, list<array<array-key, mixed>>} */
    private function suppressions(Document $document): array
    {
        $suppressions = [];
        $warnings = [];
        foreach ($this->comments($document) as $comment) {
            if (!preg_match_all(self::DIRECTIVE_PATTERN, $comment->content, $matches, \PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as [$directive, $commentOffset]) {
                $directive = rtrim($directive);
                $sourceOffset = $comment->contentStartOffset + $commentOffset;
                if (!preg_match(self::VALID_DIRECTIVE_PATTERN, $directive, $parsed)) {
                    $warnings[] = $this->warning(
                        $document->text,
                        $sourceOffset,
                        \strlen($directive),
                        'Invalid diagnostic suppression; expected "@symfony-lsp-ignore code".',
                    );

                    continue;
                }

                $codes = array_map('trim', explode(',', $parsed['codes']));
                $valid = true;
                foreach ($codes as $code) {
                    if ($this->diagnosticCodes->contains($code)) {
                        continue;
                    }
                    $valid = false;
                    $warnings[] = $this->warning(
                        $document->text,
                        $sourceOffset,
                        \strlen($directive),
                        \sprintf('Unknown diagnostic code "%s" in suppression.', $code),
                    );
                }
                if ($valid) {
                    $suppressions[] = new DiagnosticSuppression($this->targetLine($document->text, $comment), $codes);
                }
            }
        }

        return [$suppressions, $warnings];
    }

    /** @return list<SourceComment> */
    private function comments(Document $document): array
    {
        return match ($document->languageId) {
            'php' => $this->phpComments->comments($document->text),
            'twig' => $this->twigComments->comments($document->text),
            'yaml' => $this->yamlComments->comments($document->text),
            'xml' => $this->xmlComments->comments($document->text),
            default => [],
        };
    }

    private function targetLine(string $source, SourceComment $comment): int
    {
        $startLine = $this->positions->toPosition($source, $comment->startOffset)->line;
        $endLine = $this->positions->toPosition($source, max($comment->startOffset, $comment->endOffset - 1))->line;
        $lineStart = strrpos(substr($source, 0, $comment->startOffset), "\n");
        $lineStart = false === $lineStart ? 0 : $lineStart + 1;
        $lineEnd = strpos($source, "\n", $comment->endOffset);
        $lineEnd = false === $lineEnd ? \strlen($source) : $lineEnd;

        if ('' !== trim(substr($source, $lineStart, $comment->startOffset - $lineStart))) {
            return $startLine;
        }
        if ('' !== trim(substr($source, $comment->endOffset, $lineEnd - $comment->endOffset))) {
            return $endLine;
        }

        return $endLine + 1;
    }

    /** @return array<array-key, mixed> */
    private function warning(string $source, int $offset, int $length, string $message): array
    {
        return $this->protocol->diagnostic(
            $this->positions->toRange($source, $offset, max(1, $length)),
            2,
            'suppression.invalid',
            $message,
        );
    }
}
