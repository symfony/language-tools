<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Parser\Xml\XmlCommentParser;
use Symfony\Lsp\Parser\Xml\XmlElementEnd;
use Symfony\Lsp\Parser\Xml\XmlElementStart;
use Symfony\Lsp\Parser\Xml\XmlParserInterface;

final class XmlConfigurationAnalyzer
{
    public function __construct(
        private readonly XmlParserInterface $parser,
        private readonly XmlCommentParser $comments,
    ) {
    }

    /** @return list<XmlConfigurationOccurrence|XmlConfigurationStructureError> */
    public function events(string $source, ConfigurationIndex $index): array
    {
        $document = $this->parser->parse($source);
        $events = [];
        $contexts = [];
        foreach ($document->events as $event) {
            if (!$event instanceof XmlElementStart) {
                continue;
            }
            $context = null === $event->parentIdentity ? [] : ($contexts[$event->parentIdentity] ?? []);
            $path = $this->elementPath($context, $event->qualifiedName, $index);
            $contexts[$event->identity] = $path ?? $context;
            $attributes = [];
            foreach ($event->attributes as $attribute) {
                if ('xmlns' === $attribute->qualifiedName || str_starts_with($attribute->qualifiedName, 'xmlns:')) {
                    continue;
                }
                $attributes[] = new XmlConfigurationAttribute(
                    str_replace('-', '_', $attribute->qualifiedName),
                    $attribute->value,
                    $attribute->nameStartOffset,
                    $attribute->nameEndOffset,
                );
            }
            $events[] = new XmlConfigurationOccurrence(
                $path,
                $event->qualifiedName,
                $event->nameStartOffset,
                $event->nameEndOffset,
                $attributes,
            );
        }
        foreach ($document->diagnostics as $diagnostic) {
            $events[] = new XmlConfigurationStructureError($diagnostic->message, $diagnostic->startOffset, $diagnostic->endOffset);
        }

        return $events;
    }

    /** @return array{list<string>, ConfigurationNode}|null */
    public function resolveNode(string $source, ConfigurationIndex $index, int $cursor): ?array
    {
        foreach ($this->events($source, $index) as $event) {
            if (!$event instanceof XmlConfigurationOccurrence || null === $event->path || $cursor < $event->startOffset || $cursor > $event->endOffset) {
                continue;
            }
            $node = $index->find($event->path);

            return null === $node ? null : [$event->path, $node];
        }

        return null;
    }

    /** @return array{path: list<string>|null, prefix: string, start: int, alias: string, attribute: bool}|null */
    public function completionContext(string $source, ConfigurationIndex $index, int $cursor): ?array
    {
        $before = substr($this->comments->mask($source), 0, $cursor);
        if (1 === preg_match('/<(?<element>[A-Za-z_][A-Za-z0-9_.-]*(?::[A-Za-z_][A-Za-z0-9_.-]*)?)\b[^<>]*\s+(?<prefix>[A-Za-z_][A-Za-z0-9_.-]*)?$/', $before, $match)) {
            $tagOffset = strrpos($before, '<');
            if (false !== $tagOffset) {
                $parentPath = $this->pathAtOffset($source, $index, $tagOffset);
                $prefix = $match['prefix'] ?? '';

                return [
                    'path' => $this->elementPath($parentPath, $match['element'], $index),
                    'prefix' => $prefix,
                    'start' => $cursor - \strlen($prefix),
                    'alias' => '',
                    'attribute' => true,
                ];
            }
        }
        if (1 !== preg_match('/<(?:(?<alias>[A-Za-z_][A-Za-z0-9_.-]*):)?(?<prefix>[A-Za-z_][A-Za-z0-9_.-]*)?$/', $before, $match)) {
            return null;
        }
        $alias = $match['alias'] ?? '';
        $prefix = $match['prefix'] ?? '';

        return [
            'path' => $this->pathAtOffset($source, $index, $cursor - \strlen($match[0])),
            'prefix' => $prefix,
            'start' => $cursor - \strlen($prefix),
            'alias' => $alias,
            'attribute' => false,
        ];
    }

    /** @return list<string> */
    private function pathAtOffset(string $source, ConfigurationIndex $index, int $offset): array
    {
        $contexts = [];
        $stack = [];
        foreach ($this->parser->parse($source)->events as $event) {
            if ($event->startOffset >= $offset) {
                break;
            }
            if ($event instanceof XmlElementStart) {
                $context = null === $event->parentIdentity ? [] : ($contexts[$event->parentIdentity] ?? []);
                $contexts[$event->identity] = $this->elementPath($context, $event->qualifiedName, $index) ?? $context;
                if (!$event->selfClosing) {
                    $stack[] = $event->identity;
                }
            } elseif ($event instanceof XmlElementEnd && null !== $event->identity) {
                $position = array_search($event->identity, $stack, true);
                if (false !== $position) {
                    $stack = \array_slice($stack, 0, $position);
                }
            }
        }

        return [] === $stack ? [] : ($contexts[$stack[array_key_last($stack)]] ?? []);
    }

    /**
     * @param list<string> $stack
     *
     * @return list<string>|null
     */
    private function elementPath(array $stack, string $qualifiedName, ConfigurationIndex $index): ?array
    {
        if (str_contains($qualifiedName, ':')) {
            [$alias, $name] = explode(':', $qualifiedName, 2);
            $name = str_replace('-', '_', $name);
            if ('config' === $name && isset($index->roots()[$alias])) {
                return [$alias];
            }
            if (([] === $stack || $stack[0] === $alias) && isset($index->roots()[$alias])) {
                return [...([] === $stack ? [$alias] : $stack), $name];
            }

            return null;
        }
        $name = str_replace('-', '_', $qualifiedName);
        if ([] === $stack && !isset($index->roots()[$name])) {
            return null;
        }

        return [] === $stack ? [$name] : [...$stack, $name];
    }
}
