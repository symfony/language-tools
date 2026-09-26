<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\CodeActionDiagnostic;
use Symfony\Lsp\Protocol\CodeActionRequest;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class ConfigurationCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly ProjectPathResolver $paths,
        private readonly PositionConverter $converter,
        private readonly ConfigurationIndexRegistry $indexes,
        private readonly RouteIndexRegistry $routes,
        private readonly RuntimeConfiguration $runtime,
        private readonly YamlConfigurationParser $yaml,
        private readonly PhpConfigurationAnalyzer $php,
        private readonly XmlConfigurationAnalyzer $xml,
        private readonly UnknownNameCodeActionBuilder $unknownNames,
    ) {
    }

    public function actions(CodeActionRequest $request): array
    {
        if (!\in_array($request->document->languageId, ['php', 'xml', 'yaml'], true)) {
            return [];
        }
        $relative = $this->paths->relative($request->project, $request->document->uri);
        if (null === $relative || !$this->paths->isApplicationOwned($request->project, $request->document->uri)
            || !str_starts_with($relative, 'config/') || str_starts_with($relative, 'config/routes.')
            || str_starts_with($relative, 'config/routes/') || $this->routes->forProject($request->project)->isResource($relative)
        ) {
            return [];
        }
        $index = $this->indexes->forProject($request->project);
        $yamlOccurrences = $phpOccurrences = $xmlEvents = null;
        $actions = [];
        foreach ($request->diagnostics('config.unknown_key') as $diagnostic) {
            $replacements = match ($request->document->languageId) {
                'yaml' => $this->yamlReplacements($request->document, $request->project, $index, $diagnostic, $yamlOccurrences ??= $this->yaml->parse($request->document->text, $index)),
                'php' => $this->phpReplacements($request->document, $index, $diagnostic, $phpOccurrences ??= $this->php->occurrences($request->document->text, $index)),
                'xml' => $this->xmlReplacements($request->document, $index, $diagnostic, $xmlEvents ??= $this->xml->events($request->document->text, $index)),
            };
            array_push($actions, ...$replacements);
        }

        return $actions;
    }

    /**
     * @param list<ConfigurationOccurrence> $occurrences
     *
     * @return list<array<array-key, mixed>>
     */
    private function yamlReplacements(Document $document, Project $project, ConfigurationIndex $index, CodeActionDiagnostic $diagnostic, array $occurrences): array
    {
        $scope = 'when@'.$this->runtime->environment($project);
        foreach ($occurrences as $occurrence) {
            if (!\in_array($occurrence->scope, ['base', $scope], true) || !$occurrence->keyRange->equals($diagnostic->range)) {
                continue;
            }
            $parent = $index->find(\array_slice($occurrence->path, 0, -1), $occurrence->sequenceDepths, $occurrence->literalDepths);
            if (null === $parent || null !== $index->find($occurrence->path, $occurrence->sequenceDepths, $occurrence->literalDepths)
                || $index->allowsUnknownKeys($occurrence->path, $occurrence->sequenceDepths, $occurrence->literalDepths)
            ) {
                break;
            }
            $name = $occurrence->path[\count($occurrence->path) - 1] ?? null;
            if (null === $name) {
                break;
            }

            return $this->unknownNames->replacements($document, $diagnostic->diagnostic, $occurrence->keyRange, $name, $this->childNames($parent));
        }

        return [];
    }

    /**
     * @param list<PhpConfigurationOccurrence> $occurrences
     *
     * @return list<array<array-key, mixed>>
     */
    private function phpReplacements(Document $document, ConfigurationIndex $index, CodeActionDiagnostic $diagnostic, array $occurrences): array
    {
        foreach ($occurrences as $occurrence) {
            $range = $this->converter->toRange($document->text, $occurrence->startOffset, $occurrence->endOffset - $occurrence->startOffset);
            if (!$range->equals($diagnostic->range)) {
                continue;
            }
            $parent = $index->find(\array_slice($occurrence->schemaPath, 0, -1));
            if (null === $parent || null !== $index->find($occurrence->schemaPath) || $index->allowsUnknownKeys($occurrence->schemaPath)) {
                break;
            }
            $name = substr($document->text, $occurrence->startOffset, $occurrence->endOffset - $occurrence->startOffset);
            $candidates = array_map(ConfigurationNode::phpMethodName(...), $this->childNames($parent));

            return $this->unknownNames->replacements($document, $diagnostic->diagnostic, $range, $name, $candidates);
        }

        return [];
    }

    /**
     * @param list<XmlConfigurationOccurrence|XmlConfigurationStructureError> $events
     *
     * @return list<array<array-key, mixed>>
     */
    private function xmlReplacements(Document $document, ConfigurationIndex $index, CodeActionDiagnostic $diagnostic, array $events): array
    {
        foreach ($events as $event) {
            if (!$event instanceof XmlConfigurationOccurrence || null === $event->path) {
                continue;
            }
            $node = $index->find($event->path);
            $elementRange = $this->converter->toRange($document->text, $event->startOffset, $event->endOffset - $event->startOffset);
            if ($elementRange->equals($diagnostic->range)) {
                $parent = $index->find(\array_slice($event->path, 0, -1));
                if (null === $parent || null !== $node || $index->allowsUnknownKeys($event->path)
                    || (!$event->selfClosing && null === $event->closingNameOffset)
                ) {
                    return [];
                }
                $name = substr($document->text, $event->startOffset, $event->endOffset - $event->startOffset);
                $prefixLength = strrpos($name, ':');
                $prefixLength = false === $prefixLength ? 0 : $prefixLength + 1;
                $range = $this->converter->toRange($document->text, $event->startOffset + $prefixLength, \strlen($name) - $prefixLength);
                $closingRanges = null === $event->closingNameOffset ? [] : [
                    $this->converter->toRange($document->text, $event->closingNameOffset + $prefixLength, \strlen($name) - $prefixLength),
                ];

                return $this->unknownNames->replacements($document, $diagnostic->diagnostic, $range, substr($name, $prefixLength), $this->xmlNames($parent), $closingRanges);
            }
            if (null === $node) {
                continue;
            }
            foreach ($event->attributes as $attribute) {
                $range = $this->converter->toRange($document->text, $attribute->startOffset, $attribute->endOffset - $attribute->startOffset);
                if ($range->equals($diagnostic->range) && null === $node->child($attribute->name)
                    && !$index->allowsUnknownKeys([...$event->path, $attribute->name])
                ) {
                    return $this->unknownNames->replacements($document, $diagnostic->diagnostic, $range, str_replace('_', '-', $attribute->name), $this->xmlNames($node));
                }
            }
        }

        return [];
    }

    /** @return list<string> */
    private function childNames(ConfigurationNode $parent): array
    {
        $names = $parent->childNames();
        if ([] === $parent->children && null !== $parent->prototype) {
            array_push($names, ...$parent->prototype->childNames());
        }

        return $names;
    }

    /** @return list<string> */
    private function xmlNames(ConfigurationNode $parent): array
    {
        return array_map(static fn (string $name): string => str_replace('_', '-', $name), $this->childNames($parent));
    }
}
