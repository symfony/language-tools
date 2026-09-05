<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class ConfigurationDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly ProjectPathResolver $projectPaths,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly ConfigurationIndexRegistry $indexes,
        private readonly RouteIndexRegistry $routeIndexes,
        private readonly RuntimeConfiguration $runtimeConfiguration,
        private readonly YamlConfigurationParser $yaml,
        private readonly ConfigurationValueValidator $values,
        private readonly PhpConfigurationAnalyzer $php,
        private readonly XmlConfigurationAnalyzer $xml,
        private readonly YamlIndentationAnalyzer $indentation,
        private readonly ConfigurationValidationReconciler $validation,
    ) {
    }

    public function name(): string
    {
        return 'configuration';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || !\in_array($request->document->languageId, ['php', 'xml', 'yaml'], true)) {
            return null;
        }
        // bundle-internal fixtures target other kernels, so only the
        // application's own configuration is validated against its trees
        $relativePath = $this->projectPaths->relative($request->project, $request->document->uri);
        if (null === $relativePath
            || !str_starts_with($relativePath, 'config/')
            || str_starts_with($relativePath, 'config/routes.')
            || str_starts_with($relativePath, 'config/routes/')
            || $this->routeIndexes->forProject($request->project)->isResource($relativePath)
        ) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $diagnostics = match ($request->document->languageId) {
            'php' => $this->diagnosePhp($request->document, $index),
            'xml' => $this->diagnoseXml($request->document, $index),
            default => $this->diagnoseYaml($request->document, $request->project, $index),
        };

        return $this->validation->applyValidation($request->document, $request->project, $relativePath, $diagnostics);
    }

    /** @return list<array<array-key, mixed>> */
    private function diagnoseYaml(Document $document, Project $project, ConfigurationIndex $index): array
    {
        $environmentScope = 'when@'.$this->runtimeConfiguration->environment($project);
        $occurrences = $this->yaml->parse($document->text, $index, resolveAliasesAndMerges: true);
        $diagnostics = [];
        $seen = [];
        foreach ($occurrences as $occurrence) {
            if (!\in_array($occurrence->scope, ['base', $environmentScope], true)) {
                continue;
            }
            $path = $occurrence->path;
            $root = $path[0] ?? null;
            if (null === $root || \in_array($root, ['parameters', 'services'], true) || !isset($index->roots()[$root])) {
                continue;
            }
            $key = implode('.', $path);
            $identity = $occurrence->scope.'|'.$key;
            if (isset($seen[$identity]) && !$occurrence->sequenceItem()) {
                $diagnostics[] = $this->diagnostic($occurrence->keyRange, 1, 'config.duplicate_key', \sprintf('Configuration key "%s" is duplicated.', $key));
            }
            $seen[$identity] = true;
            $node = $index->find($path, $occurrence->sequenceDepths, $occurrence->literalDepths);
            if (null === $node) {
                if (!$index->allowsUnknownKeys($path, $occurrence->sequenceDepths, $occurrence->literalDepths)) {
                    $diagnostics[] = $this->diagnostic($occurrence->keyRange, 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', $key));
                }
                continue;
            }
            if ($node->deprecated) {
                $diagnostics[] = $this->diagnostic($occurrence->keyRange, 2, 'config.deprecated_key', \sprintf('Configuration key "%s" is deprecated.', $key));
            }
            $environmentValue = $occurrence->hasResolvedValue
                ? (\is_string($occurrence->resolvedValue) ? $occurrence->resolvedValue : null)
                : $occurrence->value;
            $environmentType = null === $environmentValue ? null : $this->values->environmentType($project, $environmentValue);
            if (null !== $environmentType && !$this->values->acceptsType($node, $environmentType)) {
                $diagnostics[] = $this->diagnostic($occurrence->valueRange, 1, 'env.incompatible_type', \sprintf('Environment expression returns %s, but "%s" expects %s.', $environmentType, $key, $node->type));
            } elseif ($occurrence->hasResolvedValue && !$this->values->acceptsResolvedValue($node, $occurrence->resolvedValue)) {
                $diagnostics[] = $this->diagnostic($occurrence->valueRange, 1, 'config.invalid_type', \sprintf('Expected %s for "%s".', $node->type, $key));
            } elseif ('' !== $occurrence->value && !$this->values->acceptsValue($node, $occurrence->value)) {
                $diagnostics[] = $this->diagnostic($occurrence->valueRange, 1, 'config.invalid_type', \sprintf('Expected %s for "%s".', $node->type, $key));
            }
        }
        foreach ($this->indentation->tabIndentedLines($document->text) as $range) {
            $diagnostics[] = $this->diagnostic($range, 1, 'config.malformed_structure', 'YAML indentation cannot contain tabs.');
        }

        return $diagnostics;
    }

    /** @return list<array<array-key, mixed>> */
    private function diagnosePhp(Document $document, ConfigurationIndex $index): array
    {
        $diagnostics = [];
        foreach ($this->php->occurrences($document->text, $index) as $occurrence) {
            $node = $index->find($occurrence->schemaPath);
            $range = $this->converter->toRange($document->text, $occurrence->startOffset, $occurrence->endOffset - $occurrence->startOffset);
            $key = implode('.', $occurrence->path);
            if (null === $node) {
                if (!$index->allowsUnknownKeys($occurrence->schemaPath)) {
                    $diagnostics[] = $this->diagnostic($range, 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', $key));
                }
                continue;
            }
            if ($node->deprecated) {
                $diagnostics[] = $this->diagnostic($range, 2, 'config.deprecated_key', \sprintf('Configuration key "%s" is deprecated.', $key));
            }
            if (!$this->values->acceptsPhpLiteral($node, $occurrence->literal)) {
                $diagnostics[] = $this->diagnostic($range, 1, 'config.invalid_type', \sprintf('Expected %s for "%s".', $node->type, $key));
            }
        }

        return $diagnostics;
    }

    /** @return list<array<array-key, mixed>> */
    private function diagnoseXml(Document $document, ConfigurationIndex $index): array
    {
        $diagnostics = [];
        foreach ($this->xml->events($document->text, $index) as $event) {
            $range = $this->converter->toRange($document->text, $event->startOffset, $event->endOffset - $event->startOffset);
            if ($event instanceof XmlConfigurationStructureError) {
                $diagnostics[] = $this->diagnostic($range, 1, 'config.malformed_structure', $event->message);
                continue;
            }
            if (null === $event->path) {
                continue;
            }
            $node = $index->find($event->path);
            $key = implode('.', $event->path);
            if (null === $node) {
                if (!$index->allowsUnknownKeys($event->path)) {
                    $diagnostics[] = $this->diagnostic($range, 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', $key));
                }
                continue;
            }
            if ($node->deprecated) {
                $diagnostics[] = $this->diagnostic($range, 2, 'config.deprecated_key', \sprintf('Configuration key "%s" is deprecated.', $key));
            }
            foreach ($event->attributes as $attribute) {
                $child = $node->child($attribute->name);
                $attributePath = [...$event->path, $attribute->name];
                $attributeRange = $this->converter->toRange($document->text, $attribute->startOffset, $attribute->endOffset - $attribute->startOffset);
                if (null === $child) {
                    if (!$index->allowsUnknownKeys($attributePath)) {
                        $diagnostics[] = $this->diagnostic($attributeRange, 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', implode('.', $attributePath)));
                    }
                } elseif (!$this->values->acceptsValue($child, $attribute->value)) {
                    $diagnostics[] = $this->diagnostic($attributeRange, 1, 'config.invalid_type', \sprintf('Expected %s for "%s".', $child->type, implode('.', $attributePath)));
                }
            }
        }

        return $diagnostics;
    }

    /** @return array{range: array<string, array<string, int>>, severity: int, source: string, code: string, message: string} */
    private function diagnostic(Range $range, int $severity, string $code, string $message): array
    {
        return $this->protocol->diagnostic($range, $severity, $code, $message);
    }
}
