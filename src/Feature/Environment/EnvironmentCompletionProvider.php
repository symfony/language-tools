<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Parser\CommentParserRegistry;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class EnvironmentCompletionProvider implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly EnvironmentIndexRegistry $indexes,
        private readonly CommentParserRegistry $comments,
        private readonly YamlDocumentParser $yamlParser,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $cursor = $this->converter->toByteOffset($request->document->text, $request->position);
        $textBeforeCursor = $this->textBeforeCursor($request->document->languageId, $request->document->text, $cursor);
        if (null === $textBeforeCursor || !preg_match('/%env\(([^)]*)$/', $textBeforeCursor, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $expression = $match[1][0];
        $separator = strrpos($expression, ':');
        $prefix = false === $separator ? $expression : substr($expression, $separator + 1);
        $start = $cursor - \strlen($prefix);
        $end = $this->converter->toPosition($request->document->text, $cursor + strspn(substr($request->document->text, $cursor), 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_'));
        $items = [];
        $index = $this->indexes->forProject($request->project);
        foreach ($index->processors() as $name => $type) {
            if (str_starts_with($name, $prefix)) {
                $items[] = $this->item($name, $name.':', 'Environment processor returning '.$type, $request->document->text, $start, $end);
            }
        }
        foreach ($index->names() as $name) {
            if (str_starts_with($name, $prefix)) {
                $items[] = $this->item($name, $name, 'Environment variable', $request->document->text, $start, $end);
            }
        }

        return $items;
    }

    private function textBeforeCursor(string $languageId, string $text, int $cursor): ?string
    {
        if ('yaml' === $languageId) {
            foreach ($this->yamlParser->parseDocument($text)->scalars as $scalar) {
                if ($cursor < $scalar->contentStartByte || $cursor > $scalar->contentEndByte) {
                    continue;
                }

                $contentOffset = $scalar->contentStartByte - $scalar->startByte;

                return substr($scalar->raw, $contentOffset, $cursor - $scalar->contentStartByte);
            }

            return null;
        }

        return substr($this->comments->mask($languageId, $text), 0, $cursor);
    }

    /** @return array<array-key, mixed> */
    private function item(string $label, string $newText, string $detail, string $text, int $start, Position $end): array
    {
        $position = $this->converter->toPosition($text, $start);

        return ['label' => $label, 'kind' => 12, 'detail' => $detail, 'textEdit' => $this->protocol->textEdit(new Range($position, $end), $newText)];
    }
}
