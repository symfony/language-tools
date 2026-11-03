<?php

namespace Symfony\Lsp\Feature\Css;

use Symfony\Lsp\Document\Color;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DocumentColorProviderInterface;
use Symfony\Lsp\Parser\Php\PhpLiteralArray;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlScalarStyle;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\ColorPresentationRequest;
use Symfony\Lsp\Protocol\DocumentRequest;
use Symfony\Lsp\Protocol\LspProtocolMapper;

/**
 * Shows the color of each symfony/ux-css color token in the configuration of
 * the bundle, for raw colors and for references to other color tokens.
 */
final class CssTokenColorProvider implements DocumentColorProviderInterface
{
    private const ROOT = 'ux_css';
    private const SECTIONS = ['tokens', 'semantic_tokens'];
    private const CATEGORY = 'colors';

    public function __construct(
        private readonly ProjectPathResolver $projectPaths,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly YamlDocumentParser $yaml,
        private readonly PhpParserInterface $php,
        private readonly CssColorParser $colors,
        private readonly CssColorPresentationBuilder $presentations,
        private readonly CssReferenceTokenResolver $tokens,
    ) {
    }

    public function colors(DocumentRequest $request): array
    {
        return array_map(
            fn (CssTokenColor $color): array => $this->protocol->colorInformation($color->range, $color->color),
            $this->tokenColors($request),
        );
    }

    public function presentations(ColorPresentationRequest $request): array
    {
        $token = array_find($this->tokenColors($request), static fn (CssTokenColor $color): bool => $color->range->equals($request->range));
        if (null === $token?->notation) {
            return [];
        }
        $labels = $this->presentations->presentations($request->color, $token->notation);
        if ($this->same($token->color, $request->color)) {
            $labels = array_values(array_unique([trim($token->value), ...$labels]));
        }

        return array_map(
            fn (string $label): array => $this->protocol->colorPresentation(
                $label,
                $token->plain && str_starts_with($label, '#') ? $this->protocol->textEdit($token->range, "'".$label."'") : null,
            ),
            $labels,
        );
    }

    /** @return list<CssTokenColor> */
    private function tokenColors(DocumentRequest $request): array
    {
        $document = $request->document;
        if (!\in_array($document->languageId, ['yaml', 'php'], true) || !str_contains($document->text, self::ROOT)) {
            return [];
        }
        $relativePath = $this->projectPaths->relative($request->project, $document->uri);
        if (null === $relativePath || null === $request->project->configRelativePath($relativePath)) {
            return [];
        }
        $values = 'yaml' === $document->languageId ? $this->yamlValues($document->text) : $this->phpValues($document->text);
        $tokens = null;
        $colors = [];
        foreach ($values as [$value, $start, $end, $plain]) {
            if (1 === preg_match('/^\s*\{'.self::CATEGORY.'\.([^{}]+)\}\s*$/D', $value, $match)) {
                $tokens ??= $this->tokens->tokens($request->project)[self::CATEGORY] ?? [];
                $color = isset($tokens[$match[1]]) ? $this->colors->parse($tokens[$match[1]]) : null;
                $notation = null;
            } else {
                $color = $this->colors->parse($value);
                $notation = $color?->notation;
            }
            if (null !== $color) {
                $colors[] = new CssTokenColor($this->converter->toRange($document->text, $start, $end - $start), $value, $color->color, $notation, $plain);
            }
        }

        return $colors;
    }

    /** @return list<array{string, int, int, bool}> */
    private function yamlValues(string $text): array
    {
        $values = [];
        foreach ($this->yaml->parseDocument($text)->scalars as $scalar) {
            $path = $scalar->path;
            if (\count($path) < 4
                || self::ROOT !== $path[0]
                || !\in_array(str_replace('-', '_', $path[1]), self::SECTIONS, true)
                || self::CATEGORY !== $path[2]
                || null !== $scalar->tag
                || $scalar->isSequenceItem()
                || \in_array($scalar->style, [YamlScalarStyle::BlockLiteral, YamlScalarStyle::BlockFolded], true)
            ) {
                continue;
            }
            $values[] = [$scalar->value, $scalar->contentStartByte, $scalar->contentEndByte, YamlScalarStyle::Plain === $scalar->style];
        }

        return $values;
    }

    /** @return list<array{string, int, int, bool}> */
    private function phpValues(string $text): array
    {
        $document = $this->php->parse($text);
        $roots = [];
        foreach ($document->methodCalls as $call) {
            if (0 === strcasecmp('extension', $call->method) && self::ROOT === $call->namedOrPositionalArgument('namespace', 0)?->stringLiteral?->value) {
                $roots[] = $document->literalArray($call->namedOrPositionalArgument('config', 1));
            }
        }
        foreach ($document->literalArrays as $array) {
            foreach ($array->entries as $entry) {
                $key = $entry->key?->value;
                if (self::ROOT === $key) {
                    $roots[] = $entry->arrayValue;
                } elseif (null !== $key && str_starts_with($key, 'when@')) {
                    $roots[] = $this->entry($entry->arrayValue, self::ROOT);
                }
            }
        }
        $values = [];
        $seen = [];
        foreach ($roots as $root) {
            if (null === $root || isset($seen[$root->startOffset])) {
                continue;
            }
            $seen[$root->startOffset] = true;
            foreach ($root->entries as $section) {
                if (null !== $section->key && \in_array(str_replace('-', '_', $section->key->value), self::SECTIONS, true)) {
                    $this->collectStrings($this->entry($section->arrayValue, self::CATEGORY), $values);
                }
            }
        }
        usort($values, static fn (array $left, array $right): int => $left[1] <=> $right[1]);

        return $values;
    }

    private function entry(?PhpLiteralArray $array, string $key): ?PhpLiteralArray
    {
        foreach ($array->entries ?? [] as $entry) {
            if ($key === $entry->key?->value) {
                return $entry->arrayValue;
            }
        }

        return null;
    }

    /** @param list<array{string, int, int, bool}> $values */
    private function collectStrings(?PhpLiteralArray $array, array &$values): void
    {
        foreach ($array->entries ?? [] as $entry) {
            if (null !== $entry->stringValue) {
                $values[] = [$entry->stringValue->value, $entry->stringValue->startOffset, $entry->stringValue->endOffset, false];
            }
            $this->collectStrings($entry->arrayValue, $values);
        }
    }

    private function same(Color $left, Color $right): bool
    {
        $tolerance = 0.5 / 255;

        return abs($left->red - $right->red) <= $tolerance
            && abs($left->green - $right->green) <= $tolerance
            && abs($left->blue - $right->blue) <= $tolerance
            && abs($left->alpha - $right->alpha) <= $tolerance;
    }
}
