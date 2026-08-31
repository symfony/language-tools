<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;

final class YamlMetadataExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly YamlConfigurationParser $yaml,
    ) {
    }

    /** @return list<MetadataSourceSymbol> */
    public function symbols(string $uri, string $text): array
    {
        $symbols = [];
        foreach ($this->yaml->parse($text) as $occurrence) {
            $path = $occurrence->path;
            if (1 === \count($path) && str_contains($path[0], '\\')) {
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::MappedClass,
                    $path[0],
                    $uri,
                    $occurrence->keyRange,
                    false,
                );
            }
            if (3 === \count($path) && \in_array($path[1], ['properties', 'attributes'], true)) {
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::Property,
                    $path[0].'::$'.$path[2],
                    $uri,
                    $occurrence->keyRange,
                    false,
                );
            }
            if (4 === \count($path) && 'properties' === $path[1]) {
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::Constraint,
                    $path[3],
                    $uri,
                    $occurrence->keyRange,
                    false,
                );
            }
            if ([] === $path || 'groups' !== $path[array_key_last($path)]) {
                continue;
            }
            $start = $this->converter->toByteOffset($text, $occurrence->valueRange->start);
            $end = $this->converter->toByteOffset($text, $occurrence->valueRange->end);
            $value = substr($text, $start, $end - $start);
            preg_match_all('/[A-Za-z_][A-Za-z0-9_.:-]*/', $value, $names, \PREG_OFFSET_CAPTURE);
            foreach ($names[0] as [$name, $offset]) {
                $symbols[] = new MetadataSourceSymbol(MetadataSymbolKind::SerializerGroup, $name, $uri, $this->converter->toRange($text, $start + $offset, \strlen($name)), true);
            }
        }

        return $symbols;
    }

    /** @return list<array{constraint: string, option: string, range: Range}> */
    public function constraintOptions(string $text): array
    {
        $options = [];
        foreach ($this->yaml->parse($text) as $occurrence) {
            $path = $occurrence->path;
            $count = \count($path);
            if ($count < 5 || 'properties' !== $path[1]) {
                continue;
            }
            $options[] = [
                'constraint' => $path[$count - 2],
                'option' => $path[$count - 1],
                'range' => $occurrence->keyRange,
            ];
        }

        return $options;
    }

    public function completionContext(string $text, int $offset): ?MetadataCompletionContext
    {
        $before = substr($text, 0, $offset);
        $lineOffset = strrpos($before, "\n");
        $lineOffset = false === $lineOffset ? 0 : $lineOffset + 1;
        $line = substr($before, $lineOffset);
        $parent = $this->yaml->parentPath($text, $lineOffset);
        if (2 === \count($parent) && \in_array($parent[1], ['properties', 'attributes'], true) && preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::Property, $match[1][0], $text, $lineOffset + $match[1][1], $parent[0]);
        }
        if (\count($parent) >= 4 && 'properties' === $parent[1] && preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::ConstraintOption, $match[1][0], $text, $lineOffset + $match[1][1], $parent[array_key_last($parent)]);
        }
        if (\count($parent) >= 3 && 'properties' === $parent[1] && preg_match('/^\s*-\s*([A-Za-z_][A-Za-z0-9_]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::Constraint, $match[1][0], $text, $lineOffset + $match[1][1]);
        }
        if ([] !== $parent && 'groups' === $parent[array_key_last($parent)] && preg_match('/^\s*-\s*["\']?([A-Za-z_][A-Za-z0-9_.:-]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::SerializerGroup, $match[1][0], $text, $lineOffset + $match[1][1]);
        }
        if (preg_match('/\bgroups\s*:\s*\[[^\]]*["\']?([A-Za-z_][A-Za-z0-9_.:-]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::SerializerGroup, $match[1][0], $text, $lineOffset + $match[1][1]);
        }

        return null;
    }

    private function context(MetadataCompletionKind $kind, string $prefix, string $text, int $offset, ?string $owner = null): MetadataCompletionContext
    {
        return new MetadataCompletionContext($kind, $prefix, $this->converter->toRange($text, $offset, \strlen($prefix)), $owner);
    }
}
