<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlMapping;
use Symfony\Lsp\Parser\Yaml\YamlScalar;
use Symfony\Lsp\Parser\Yaml\YamlSequenceItem;

final class EventYamlListenerAnalyzer
{
    private const LISTENER_TAG = 'kernel.event_listener';

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly YamlDocumentParser $parser,
    ) {
    }

    /** @return list<EventSourceSymbol> */
    public function symbols(string $uri, string $text): array
    {
        $symbols = [];
        foreach ($this->listenerEvents($text) as [, $scalar]) {
            if (null === $scalar || '' === $scalar->value) {
                continue;
            }
            $symbols[] = new EventSourceSymbol(
                ltrim($scalar->value, '\\'),
                $uri,
                new Range(
                    $this->converter->toPosition($text, $scalar->contentStartByte),
                    $this->converter->toPosition($text, $scalar->contentEndByte),
                ),
                true,
            );
        }

        return $symbols;
    }

    public function completionPrefix(string $text, int $offset): ?string
    {
        foreach ($this->listenerEvents($text) as [$mapping, $scalar]) {
            if (null === $scalar) {
                if ($offset === $mapping->valueStartByte && $offset === $mapping->valueEndByte) {
                    return '';
                }
                continue;
            }
            if ($offset >= $scalar->contentStartByte && $offset <= $scalar->contentEndByte) {
                return substr($text, $scalar->contentStartByte, $offset - $scalar->contentStartByte);
            }
        }

        return null;
    }

    /**
     * Event values of `kernel.event_listener` tags, paired with the scalar
     * holding the value when the tag entry declares one.
     *
     * @return list<array{YamlMapping, ?YamlScalar}>
     */
    private function listenerEvents(string $text): array
    {
        $document = $this->parser->parseDocument($text);
        $scalars = [];
        foreach ($document->scalars as $scalar) {
            $scalars[$scalar->startByte] ??= $scalar;
        }
        $listeners = [];
        $events = [];
        foreach ($document->mappings as $mapping) {
            $entry = $this->tagEntry($mapping);
            if (null === $entry) {
                continue;
            }
            [$identity, $key] = $entry;
            $scalar = $scalars[$mapping->valueStartByte] ?? null;
            $scalar = null !== $scalar && null === $scalar->tag && $scalar->path === $mapping->path ? $scalar : null;
            if ('name' === $key) {
                $listeners[$identity] = self::LISTENER_TAG === $scalar?->value;
            } else {
                $events[$identity] = [$mapping, $scalar];
            }
        }

        return array_values(array_intersect_key($events, array_filter($listeners)));
    }

    /**
     * The tag entry a `name` or `event` mapping belongs to, as an identity
     * shared by the keys of that entry only.
     *
     * @return array{string, string}|null
     */
    private function tagEntry(YamlMapping $mapping): ?array
    {
        $depth = \count($mapping->path) - 1;
        if ($depth < 3
            || 'services' !== $mapping->path[0]
            || 'tags' !== $mapping->path[$depth - 1]
            || !\in_array($key = $mapping->path[$depth], ['name', 'event'], true)
            || !\in_array($depth, $mapping->sequenceDepths, true)
        ) {
            return null;
        }
        $items = array_map(static fn (YamlSequenceItem $item): string => $item->pathDepth.':'.$item->index, $mapping->sequence);

        return [implode("\0", [$mapping->scope, ...\array_slice($mapping->path, 0, -1), ...$items]), $key];
    }
}
