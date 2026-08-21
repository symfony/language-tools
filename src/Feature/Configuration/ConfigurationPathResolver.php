<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\Document;

final class ConfigurationPathResolver
{
    public function phpRoot(string $before, string $variable): string
    {
        preg_match_all('/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*Config)\s+\$'.preg_quote($variable, '/').'\b/', $before, $matches);
        $class = end($matches[1]);
        if (false === $class) {
            return $this->phpMethodName($variable);
        }
        $shortName = substr($class, (int) strrpos('\\'.$class, '\\'));

        return $this->phpMethodName(substr($shortName, 0, -\strlen('Config')));
    }

    public function phpMethodName(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    /** @return array{list<string>, ConfigurationNode}|null */
    public function resolvePhpNode(Document $document, ConfigurationIndex $index, int $cursor): ?array
    {
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)((?:->[A-Za-z_][A-Za-z0-9_]*\([^)]*\))+)/', $document->text(), $chains, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($chains as $chain) {
            $path = [$this->phpRoot(substr($document->text(), 0, $chain[1][1]), $chain[1][0])];
            preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\(([^)]*)\)/', $chain[2][0], $methods, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($methods as $method) {
                $path[] = $this->phpMethodName($method[1][0]);
                $start = $chain[2][1] + $method[1][1];
                if ($cursor >= $start && $cursor <= $start + \strlen($method[1][0])) {
                    $node = $index->find($path);

                    return null === $node ? null : [$path, $node];
                }
            }
        }

        return null;
    }

    /** @return array{list<string>, ConfigurationNode}|null */
    public function resolveXmlNode(Document $document, ConfigurationIndex $index, int $cursor): ?array
    {
        $stack = [];
        preg_match_all('/<\s*(\/)?\s*([A-Za-z_][A-Za-z0-9_.-]*(?::[A-Za-z_][A-Za-z0-9_.-]*)?)([^>]*)>/', $document->text(), $tags, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($tags as $tag) {
            if ('' !== $tag[1][0]) {
                if ([] !== $stack) {
                    array_pop($stack);
                }
                continue;
            }
            $path = $this->xmlElementPath($stack, $tag[2][0], $index);
            $start = $tag[2][1];
            if (null !== $path && $cursor >= $start && $cursor <= $start + \strlen($tag[2][0])) {
                $node = $index->find($path);

                return null === $node ? null : [$path, $node];
            }
            if (null !== $path && !str_ends_with(rtrim($tag[0][0]), '/>')) {
                $stack = $path;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function xmlPath(string $text, ConfigurationIndex $index): array
    {
        $stack = [];
        preg_match_all('/<\s*(\/)?\s*([A-Za-z_][A-Za-z0-9_.-]*(?::[A-Za-z_][A-Za-z0-9_.-]*)?)[^>]*>/', $text, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ('' !== $match[1]) {
                if ([] !== $stack) {
                    array_pop($stack);
                }
                continue;
            }
            $path = $this->xmlElementPath($stack, $match[2], $index);
            if (null !== $path && !str_ends_with(rtrim($match[0]), '/>')) {
                $stack = $path;
            }
        }

        return $stack;
    }

    /**
     * @param list<string> $stack
     *
     * @return list<string>|null
     */
    public function xmlElementPath(array $stack, string $qualifiedName, ConfigurationIndex $index): ?array
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
