<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class ConfigurationDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly ProjectPathResolver $projectPaths,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly ConfigurationIndexRegistry $indexes,
        private readonly ConfigurationPathResolver $paths,
        private readonly YamlConfigurationParser $yaml,
        private readonly ConfigurationValueValidator $values,
    ) {
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || !\in_array($request->document->languageId(), ['php', 'xml', 'yaml'], true)) {
            return null;
        }
        // bundle-internal fixtures target other kernels, so only the
        // application's own configuration is validated against its trees
        $relativePath = $this->projectPaths->relative($request->project, $request->document->uri());
        if (null === $relativePath || !str_starts_with($relativePath, 'config/') || $this->isRouteResource($relativePath)) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        if ('php' === $request->document->languageId()) {
            return $this->diagnosePhp($request->document, $index);
        }
        if ('xml' === $request->document->languageId()) {
            return $this->diagnoseXml($request->document, $index);
        }
        $occurrences = $this->yaml->parse($request->document->text());
        $diagnostics = [];
        $seen = [];
        foreach ($occurrences as $occurrence) {
            $path = $occurrence->path();
            $root = $path[0] ?? null;
            if (null === $root || \in_array($root, ['parameters', 'services'], true) || !isset($index->roots()[$root])) {
                continue;
            }
            $key = implode('.', $path);
            $identity = $occurrence->scope().'|'.$key;
            if (isset($seen[$identity]) && !$occurrence->sequenceItem()) {
                $diagnostics[] = $this->diagnostic($occurrence->keyRange(), 1, 'config.duplicate_key', \sprintf('Configuration key "%s" is duplicated.', $key));
            }
            $seen[$identity] = true;
            $node = $index->find($path, $occurrence->sequenceItem());
            if (null === $node) {
                if (!$index->allowsUnknownKeys($path, $occurrence->sequenceItem())) {
                    $diagnostics[] = $this->diagnostic($occurrence->keyRange(), 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', $key));
                }
                continue;
            }
            if ($node->deprecated()) {
                $diagnostics[] = $this->diagnostic($occurrence->keyRange(), 2, 'config.deprecated_key', \sprintf('Configuration key "%s" is deprecated.', $key));
            }
            $environmentType = $this->values->environmentType($request->project, $occurrence->value());
            if (null !== $environmentType && !$this->values->acceptsType($node, $environmentType)) {
                $diagnostics[] = $this->diagnostic($occurrence->valueRange(), 1, 'env.incompatible_type', \sprintf('Environment expression returns %s, but "%s" expects %s.', $environmentType, $key, $node->type()));
            } elseif ('' !== $occurrence->value() && !$this->values->acceptsValue($node, $occurrence->value())) {
                $diagnostics[] = $this->diagnostic($occurrence->valueRange(), 1, 'config.invalid_type', \sprintf('Expected %s for "%s".', $node->type(), $key));
            }
        }
        preg_match_all('/^\t+\S.*$/m', $request->document->text(), $tabbedLines, \PREG_OFFSET_CAPTURE);
        foreach ($tabbedLines[0] as [$line, $offset]) {
            $diagnostics[] = $this->diagnostic($this->offsetRange($request->document->text(), $offset, \strlen($line)), 1, 'config.malformed_structure', 'YAML indentation cannot contain tabs.');
        }

        return $diagnostics;
    }

    /** @return list<array<array-key, mixed>> */
    private function diagnosePhp(Document $document, ConfigurationIndex $index): array
    {
        $diagnostics = [];
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)((?:->[A-Za-z_][A-Za-z0-9_]*\([^)]*\))+)/', $document->text(), $chains, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($chains as $chain) {
            $path = [$this->paths->phpRoot(substr($document->text(), 0, $chain[1][1]), $chain[1][0])];
            if (!isset($index->roots()[$path[0]])) {
                continue;
            }
            preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\(([^)]*)\)/', $chain[2][0], $methods, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($methods as $method) {
                $path[] = $this->paths->phpMethodName($method[1][0]);
                $node = $index->find($path);
                $offset = $chain[2][1] + $method[1][1];
                $range = $this->offsetRange($document->text(), $offset, \strlen($method[1][0]));
                if (null === $node) {
                    if (!$index->allowsUnknownKeys($path)) {
                        $diagnostics[] = $this->diagnostic($range, 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', implode('.', $path)));
                    }
                    break;
                }
                if ($node->deprecated()) {
                    $diagnostics[] = $this->diagnostic($range, 2, 'config.deprecated_key', \sprintf('Configuration key "%s" is deprecated.', implode('.', $path)));
                }
                $argument = trim($method[2][0]);
                if ('' !== $argument && !$this->values->acceptsValue($node, $argument)) {
                    $diagnostics[] = $this->diagnostic($range, 1, 'config.invalid_type', \sprintf('Expected %s for "%s".', $node->type(), implode('.', $path)));
                }
            }
        }

        return $diagnostics;
    }

    /** @return list<array<array-key, mixed>> */
    private function diagnoseXml(Document $document, ConfigurationIndex $index): array
    {
        $diagnostics = [];
        $stack = [];
        $elements = [];
        preg_match_all('/<\s*(\/)?\s*([A-Za-z_][A-Za-z0-9_.-]*(?::[A-Za-z_][A-Za-z0-9_.-]*)?)([^>]*)>/', $document->text(), $tags, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($tags as $tag) {
            if ('' !== $tag[1][0]) {
                $open = array_pop($elements);
                if ($open !== $tag[2][0]) {
                    $diagnostics[] = $this->diagnostic($this->offsetRange($document->text(), $tag[2][1], \strlen($tag[2][0])), 1, 'config.malformed_structure', \sprintf('Closing element "%s" does not match "%s".', $tag[2][0], $open ?? 'none'));
                }
                if ([] !== $stack) {
                    array_pop($stack);
                }
                continue;
            }
            if (!str_ends_with(rtrim($tag[0][0]), '/>')) {
                $elements[] = $tag[2][0];
            }
            $name = $tag[2][0];
            $path = $this->paths->xmlElementPath($stack, $name, $index);
            if (null === $path) {
                continue;
            }
            $node = $index->find($path);
            $nameRange = $this->offsetRange($document->text(), $tag[2][1], \strlen($name));
            if (null === $node) {
                if (!$index->allowsUnknownKeys($path)) {
                    $diagnostics[] = $this->diagnostic($nameRange, 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', implode('.', $path)));
                }
            } elseif ($node->deprecated()) {
                $diagnostics[] = $this->diagnostic($nameRange, 2, 'config.deprecated_key', \sprintf('Configuration key "%s" is deprecated.', implode('.', $path)));
            }
            if (null !== $node) {
                preg_match_all('/([A-Za-z_][A-Za-z0-9_.-]*)\s*=\s*(["\'])(.*?)\2/', $tag[3][0], $attributes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
                foreach ($attributes as $attribute) {
                    $attributeName = str_replace('-', '_', $attribute[1][0]);
                    $child = $node->child($attributeName);
                    $attributePath = [...$path, $attributeName];
                    $range = $this->offsetRange($document->text(), $tag[3][1] + $attribute[1][1], \strlen($attribute[1][0]));
                    if (null === $child) {
                        if (!$index->allowsUnknownKeys($attributePath)) {
                            $diagnostics[] = $this->diagnostic($range, 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', implode('.', $attributePath)));
                        }
                    } elseif (!$this->values->acceptsValue($child, $attribute[3][0])) {
                        $diagnostics[] = $this->diagnostic($range, 1, 'config.invalid_type', \sprintf('Expected %s for "%s".', $child->type(), implode('.', $attributePath)));
                    }
                }
            }
            if (!str_ends_with(rtrim($tag[0][0]), '/>')) {
                $stack = $path;
            }
        }
        if ([] !== $elements) {
            $diagnostics[] = $this->diagnostic($this->offsetRange($document->text(), \strlen($document->text()), 0), 1, 'config.malformed_structure', \sprintf('Element "%s" is not closed.', array_pop($elements)));
        }

        return $diagnostics;
    }

    private function isRouteResource(string $relativePath): bool
    {
        return str_starts_with($relativePath, 'config/routes/')
            || 1 === preg_match('/^config\/routes(?:\.[^\/]+)*\.(?:php|xml|ya?ml)$/i', $relativePath);
    }

    private function offsetRange(string $text, int $offset, int $length): Range
    {
        return new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + $length));
    }

    /** @return array{range: array<string, array<string, int>>, severity: int, source: string, code: string, message: string} */
    private function diagnostic(Range $range, int $severity, string $code, string $message): array
    {
        return $this->protocol->diagnostic($range, $severity, $code, $message);
    }
}
