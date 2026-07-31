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
                $mapping->path(),
                $mapping->value(),
                new Range($this->converter->toPosition($text, $mapping->keyStartByte()), $this->converter->toPosition($text, $mapping->keyEndByte())),
                new Range($this->converter->toPosition($text, $mapping->valueStartByte()), $this->converter->toPosition($text, $mapping->valueEndByte())),
                $mapping->isSequenceItem(),
                $mapping->scope(),
            );
        }

        return $occurrences;
    }
}
