<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;

final class YamlConfigurationParser
{
    public function __construct(private readonly PositionConverter $converter, private readonly YamlDocumentParser $parser)
    {
    }

    /** @return list<ConfigurationOccurrence> */
    public function parse(string $text, ?ConfigurationIndex $index = null): array
    {
        $occurrences = [];
        foreach ($this->parser->parse($text) as $mapping) {
            $path = null === $index
                ? $this->normalizePath($mapping->path())
                : $index->normalizePath($mapping->path(), $mapping->isSequenceItem());
            $occurrences[] = new ConfigurationOccurrence(
                $path,
                $mapping->value(),
                new Range($this->converter->toPosition($text, $mapping->keyStartByte()), $this->converter->toPosition($text, $mapping->keyEndByte())),
                new Range($this->converter->toPosition($text, $mapping->valueStartByte()), $this->converter->toPosition($text, $mapping->valueEndByte())),
                $mapping->isSequenceItem(),
                $mapping->scope(),
            );
        }

        return $occurrences;
    }

    /** @return list<string> */
    public function parentPath(string $text, int $offset): array
    {
        return $this->normalizePath($this->parser->parentPath($text, $offset));
    }

    /**
     * @param list<string> $path
     *
     * @return list<string>
     */
    private function normalizePath(array $path): array
    {
        foreach ($path as &$part) {
            $part = ConfigurationNode::normalizeKey($part);
        }
        unset($part);

        return $path;
    }
}
