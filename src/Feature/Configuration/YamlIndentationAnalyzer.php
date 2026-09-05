<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlScalar;
use Symfony\Lsp\Parser\Yaml\YamlScalarStyle;

final class YamlIndentationAnalyzer
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly YamlDocumentParser $parser,
    ) {
    }

    /** @return list<Range> lines whose structural indentation contains a tab */
    public function tabIndentedLines(string $text): array
    {
        if (!str_contains($text, "\t")) {
            return [];
        }
        $scalars = $this->parser->parseDocument($text)->scalars;
        $ranges = [];
        preg_match_all('/^.*(?:\R|$)/m', $text, $lines, \PREG_OFFSET_CAPTURE);
        foreach ($lines[0] as [$rawLine, $lineOffset]) {
            $line = rtrim($rawLine, "\r\n");
            $indent = strspn($line, " \t");
            if ($indent === \strlen($line) || !str_contains(substr($line, 0, $indent), "\t")) {
                continue;
            }
            if (!$this->isScalarContent($text, $scalars, $lineOffset, strspn($line, ' '))) {
                $ranges[] = $this->converter->toRange($text, $lineOffset, \strlen($line));
            }
        }

        return $ranges;
    }

    /** @param list<YamlScalar> $scalars */
    private function isScalarContent(string $text, array $scalars, int $lineOffset, int $spaces): bool
    {
        foreach ($scalars as $scalar) {
            if ($scalar->startByte >= $lineOffset || $scalar->endByte <= $lineOffset) {
                continue;
            }
            // quoted scalars continue at their own indentation, while plain and
            // block scalars only continue deeper than their first line
            $header = $this->lineIndent($text, $scalar->startByte);
            $quoted = \in_array($scalar->style, [YamlScalarStyle::SingleQuoted, YamlScalarStyle::DoubleQuoted], true);
            if ($spaces > $header || ($quoted && $spaces === $header)) {
                return true;
            }
        }

        return false;
    }

    private function lineIndent(string $text, int $offset): int
    {
        $lineStart = strrpos(substr($text, 0, $offset), "\n");

        return strspn($text, ' ', false === $lineStart ? 0 : $lineStart + 1);
    }
}
