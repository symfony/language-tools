<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Project\ProjectRegistry;

final class DependencyInjectionDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly ServiceIndexRegistry $serviceIndexes,
        private readonly ParameterIndexRegistry $parameterIndexes,
        private readonly DependencyInjectionSourceIndexRegistry $sourceIndexes,
        private readonly YamlDependencyInjectionExtractor $yamlExtractor,
        private readonly PhpAutowireReferenceExtractor $autowireExtractor,
    ) {
    }

    public function diagnostics(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }

        $document = $this->documents->get($textDocument['uri']);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $document || null === $project || !\in_array($document->languageId(), ['php', 'yaml'], true)) {
            return null;
        }

        $references = 'yaml' === $document->languageId()
            ? $this->yamlExtractor->extract($document->uri(), $document->text())->references()
            : $this->autowireExtractor->extract($document->uri(), $document->text());
        $sourceIndex = $this->sourceIndexes->forProject($project);
        $serviceIndex = $this->serviceIndexes->forProject($project);
        $parameterIndex = $this->parameterIndexes->forProject($project);
        if (!$serviceIndex->isComplete() && !$parameterIndex->isComplete()) {
            return null;
        }

        $diagnostics = [];
        foreach ($references as $reference) {
            if (DependencyInjectionSymbolKind::Service === $reference->kind()) {
                if ($reference->isOptional()
                    || !$serviceIndex->isComplete()
                    || null !== $serviceIndex->get($reference->name())
                    || [] !== $sourceIndex->serviceDeclarations($reference->name())
                ) {
                    continue;
                }

                $code = 'service.not_found';
                $message = \sprintf('Service "%s" does not exist in the selected environment.', $reference->name());
            } else {
                if (!$parameterIndex->isComplete()
                    || null !== $parameterIndex->get($reference->name())
                    || [] !== $sourceIndex->parameterDeclarations($reference->name())
                ) {
                    continue;
                }

                $code = 'parameter.not_found';
                $message = \sprintf('Parameter "%s" does not exist in the selected environment.', $reference->name());
            }

            $diagnostics[] = [
                'range' => [
                    'start' => [
                        'line' => $reference->range()->start()->line(),
                        'character' => $reference->range()->start()->character(),
                    ],
                    'end' => [
                        'line' => $reference->range()->end()->line(),
                        'character' => $reference->range()->end()->character(),
                    ],
                ],
                'severity' => 1,
                'source' => 'symfony',
                'code' => $code,
                'message' => $message,
            ];
        }

        return $diagnostics;
    }
}
