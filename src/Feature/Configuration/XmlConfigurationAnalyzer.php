<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Parser\Xml\XmlCommentParser;

final class XmlConfigurationAnalyzer
{
    public function __construct(
        private readonly XmlCommentParser $comments,
    ) {
    }

    /**
     * @return list<array{kind: 'element', path: list<string>|null, name: string, start: int, end: int, attributes: list<array{name: string, value: string, start: int, end: int}>}|array{kind: 'structure', message: string, start: int, end: int}>
     */
    public function events(string $source, ConfigurationIndex $index): array
    {
        [$events] = $this->scan($source, $index);

        return $events;
    }

    /** @return array{list<string>, ConfigurationNode}|null */
    public function resolveNode(string $source, ConfigurationIndex $index, int $cursor): ?array
    {
        foreach ($this->events($source, $index) as $event) {
            if ('element' !== $event['kind'] || null === $event['path'] || $cursor < $event['start'] || $cursor > $event['end']) {
                continue;
            }
            $node = $index->find($event['path']);

            return null === $node ? null : [$event['path'], $node];
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
                $parentPath = $this->path(substr($before, 0, $tagOffset), $index);
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
            'path' => $this->path(substr($before, 0, -\strlen($match[0])), $index),
            'prefix' => $prefix,
            'start' => $cursor - \strlen($prefix),
            'alias' => $alias,
            'attribute' => false,
        ];
    }

    /**
     * @return array{
     *     list<array{kind: 'element', path: list<string>|null, name: string, start: int, end: int, attributes: list<array{name: string, value: string, start: int, end: int}>}|array{kind: 'structure', message: string, start: int, end: int}>,
     *     list<string>
     * }
     */
    private function scan(string $source, ConfigurationIndex $index): array
    {
        $events = [];
        $stack = [];
        $elements = [];
        $text = $this->comments->mask($source);
        preg_match_all('/<\s*(\/)?\s*([A-Za-z_][A-Za-z0-9_.-]*(?::[A-Za-z_][A-Za-z0-9_.-]*)?)([^>]*)>/', $text, $tags, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($tags as $tag) {
            if ('' !== $tag[1][0]) {
                $open = array_pop($elements);
                if ($open !== $tag[2][0]) {
                    $events[] = [
                        'kind' => 'structure',
                        'message' => \sprintf('Closing element "%s" does not match "%s".', $tag[2][0], $open ?? 'none'),
                        'start' => $tag[2][1],
                        'end' => $tag[2][1] + \strlen($tag[2][0]),
                    ];
                }
                if ([] !== $stack) {
                    array_pop($stack);
                }
                continue;
            }
            $selfClosing = str_ends_with(rtrim($tag[0][0]), '/>');
            if (!$selfClosing) {
                $elements[] = $tag[2][0];
            }
            $path = $this->elementPath($stack, $tag[2][0], $index);
            $attributes = [];
            preg_match_all('/([A-Za-z_][A-Za-z0-9_.-]*)\s*=\s*(["\'])(.*?)\2/', $tag[3][0], $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($matches as $attribute) {
                $attributes[] = [
                    'name' => str_replace('-', '_', $attribute[1][0]),
                    'value' => $attribute[3][0],
                    'start' => $tag[3][1] + $attribute[1][1],
                    'end' => $tag[3][1] + $attribute[1][1] + \strlen($attribute[1][0]),
                ];
            }
            $events[] = [
                'kind' => 'element',
                'path' => $path,
                'name' => $tag[2][0],
                'start' => $tag[2][1],
                'end' => $tag[2][1] + \strlen($tag[2][0]),
                'attributes' => $attributes,
            ];
            if (null !== $path && !$selfClosing) {
                $stack = $path;
            }
        }
        if ([] !== $elements) {
            $events[] = [
                'kind' => 'structure',
                'message' => \sprintf('Element "%s" is not closed.', array_pop($elements)),
                'start' => \strlen($source),
                'end' => \strlen($source),
            ];
        }

        return [$events, $stack];
    }

    /** @return list<string> */
    private function path(string $source, ConfigurationIndex $index): array
    {
        [, $path] = $this->scan($source, $index);

        return $path;
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
