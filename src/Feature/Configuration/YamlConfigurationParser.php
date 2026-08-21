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
    public function parse(string $text): array
    {
        $occurrences = [];
        foreach ($this->parser->parse($text) as $mapping) {
            $occurrences[] = new ConfigurationOccurrence(
                $this->normalizePath($mapping->path()),
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
            $part = str_replace('-', '_', $part);
        }
        unset($part);

        return $path;
    }
}
