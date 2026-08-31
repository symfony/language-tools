<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Php\PhpObjectCreation;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpStringLiteral;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;

final class PhpTranslationReferenceExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $parser,
        private readonly PhpCommentParserInterface $comments,
        private readonly TranslationParameterAnalyzer $parameters,
    ) {
    }

    /** @return list<TranslationReference> */
    public function extract(string $uri, string $text): array
    {
        $document = $this->parser->parse($text);
        $references = [];
        foreach ($document->methodCalls as $call) {
            if ('trans' !== $call->method) {
                continue;
            }
            $key = ($call->argument('id') ?? $call->argument('key') ?? $call->positionalArgument(0))?->stringLiteral;
            if (null === $key || null === $domain = $this->domain($call->argument('domain') ?? $call->positionalArgument(2))) {
                continue;
            }
            $references[] = [
                'offset' => $key->startOffset,
                'reference' => $this->reference(
                    $key,
                    $domain,
                    $uri,
                    $text,
                    $this->parameters->php($call->argument('parameters') ?? $call->positionalArgument(1)),
                ),
            ];
        }
        foreach ($document->objectCreations as $creation) {
            if (!$this->isTranslatableMessage($creation)) {
                continue;
            }
            $key = ($creation->argument('message') ?? $creation->positionalArgument(0))?->stringLiteral;
            if (null === $key || null === $domain = $this->domain($creation->argument('domain') ?? $creation->positionalArgument(2))) {
                continue;
            }
            $references[] = [
                'offset' => $key->startOffset,
                'reference' => $this->reference(
                    $key,
                    $domain,
                    $uri,
                    $text,
                    $this->parameters->php($creation->argument('parameters') ?? $creation->positionalArgument(1)),
                ),
            ];
        }
        array_push($references, ...$this->helperReferences($uri, $text));
        usort($references, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);

        return array_column($references, 'reference');
    }

    private function domain(?PhpArgument $argument): ?string
    {
        return null === $argument ? 'messages' : $argument->stringLiteral?->value;
    }

    private function isTranslatableMessage(PhpObjectCreation $creation): bool
    {
        $separator = strrpos($creation->className, '\\');

        return 'TranslatableMessage' === (false === $separator ? $creation->className : substr($creation->className, $separator + 1));
    }

    /** @param list<string>|null $placeholders */
    private function reference(PhpStringLiteral $key, string $domain, string $uri, string $text, ?array $placeholders): TranslationReference
    {
        return new TranslationReference(
            $key->value,
            $domain,
            $uri,
            $this->converter->toRange($text, $key->startOffset, $key->endOffset - $key->startOffset),
            $placeholders,
        );
    }

    /** @return list<array{offset: int, reference: TranslationReference}> */
    private function helperReferences(string $uri, string $text): array
    {
        preg_match_all(
            '/\bt\s*\(\s*(?:message\s*:\s*)?(?|(\')((?:\\\\.|[^\'\\\\])+)\'|(\")((?:\\\\[\\\\\"]|[^\"\\\\$])+)\")/s',
            $this->comments->mask($text),
            $matches,
            \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL,
        );
        $references = [];
        foreach ($matches[2] as $i => [$raw, $offset]) {
            $quote = $matches[1][$i][0];
            if (!\is_string($raw) || !\is_string($quote)) {
                continue;
            }
            $references[] = [
                'offset' => $offset,
                'reference' => new TranslationReference(
                    PhpStringLiteralDecoder::decode($quote, $raw),
                    'messages',
                    $uri,
                    $this->converter->toRange($text, $offset, \strlen($raw)),
                ),
            ];
        }

        return $references;
    }
}
