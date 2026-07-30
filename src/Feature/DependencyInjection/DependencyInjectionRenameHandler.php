<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\RenameProviderInterface;
use Symfony\Lsp\Project\Project;

final class DependencyInjectionRenameHandler implements RenameProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly DependencyInjectionSymbolResolver $symbolResolver,
        private readonly DependencyInjectionSourceIndexRegistry $sourceIndexes,
        private readonly ServiceIndexRegistry $serviceIndexes,
        private readonly ParameterIndexRegistry $parameterIndexes,
    ) {
    }

    public function prepare(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }

        [$project, $symbol] = $resolved;
        if (!$this->isApplicationOwned($project, $symbol)) {
            return null;
        }

        return [
            'range' => $this->range($symbol->range()),
            'placeholder' => $symbol->name(),
        ];
    }

    public function rename(array $params): ?array
    {
        $newName = $params['newName'] ?? null;
        $resolved = $this->resolve($params);
        if (!\is_string($newName) || null === $resolved) {
            return null;
        }

        [$project, $symbol] = $resolved;
        if (!$this->isApplicationOwned($project, $symbol)
            || !$this->validName($symbol->kind(), $newName)
            || $this->exists($project, $symbol, $newName)
        ) {
            return null;
        }

        $index = $this->sourceIndexes->forProject($project);
        $locations = [];
        foreach ($index->references($symbol->kind(), $symbol->name()) as $reference) {
            $locations[] = [$reference->uri(), $reference->range()];
        }
        $declarations = DependencyInjectionSymbolKind::Service === $symbol->kind()
            ? $index->serviceDeclarations($symbol->name())
            : $index->parameterDeclarations($symbol->name());
        foreach ($declarations as $declaration) {
            $locations[] = [$declaration->uri(), $declaration->range()];
        }

        $editsByUri = [];
        foreach ($locations as [$uri, $range]) {
            $key = $uri.'\0'.$range->start()->line().'\0'.$range->start()->character();
            $editsByUri[$uri][$key] = [
                'range' => $this->range($range),
                'newText' => $newName,
                'annotationId' => 'dependencyInjectionRename',
            ];
        }
        ksort($editsByUri);
        $documentChanges = [];
        foreach ($editsByUri as $uri => $edits) {
            $documentChanges[] = [
                'textDocument' => ['uri' => $uri, 'version' => null],
                'edits' => array_values($edits),
            ];
        }

        $kind = DependencyInjectionSymbolKind::Service === $symbol->kind() ? 'service' : 'parameter';

        return [
            'documentChanges' => $documentChanges,
            'changeAnnotations' => [
                'dependencyInjectionRename' => [
                    'label' => \sprintf('Rename %s "%s" to "%s"', $kind, $symbol->name(), $newName),
                    'needsConfirmation' => true,
                    'description' => 'Dynamic references may remain unchanged.',
                ],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{Project, DependencyInjectionSymbol}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->documentContextResolver->resolve($params);
        if (null === $request) {
            return null;
        }

        [$document, $project, $position] = $request;
        $symbol = $this->symbolResolver->resolve(
            $document->uri(),
            $document->languageId(),
            $document->text(),
            $position,
        );

        return null === $symbol ? null : [$project, $symbol];
    }

    private function isApplicationOwned(Project $project, DependencyInjectionSymbol $symbol): bool
    {
        $index = $this->sourceIndexes->forProject($project);

        return DependencyInjectionSymbolKind::Service === $symbol->kind()
            ? [] !== $index->serviceDeclarations($symbol->name())
            : [] !== $index->parameterDeclarations($symbol->name());
    }

    private function validName(DependencyInjectionSymbolKind $kind, string $name): bool
    {
        if (DependencyInjectionSymbolKind::Service === $kind) {
            return 1 === preg_match('/^[.A-Za-z_\\\\][A-Za-z0-9_.\\\\:$-]*$/', $name);
        }

        return 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $name);
    }

    private function exists(Project $project, DependencyInjectionSymbol $symbol, string $newName): bool
    {
        if ($newName === $symbol->name()) {
            return false;
        }

        $index = $this->sourceIndexes->forProject($project);
        if (DependencyInjectionSymbolKind::Service === $symbol->kind()) {
            return null !== $this->serviceIndexes->forProject($project)->get($newName)
                || [] !== $index->serviceDeclarations($newName);
        }

        return null !== $this->parameterIndexes->forProject($project)->get($newName)
            || [] !== $index->parameterDeclarations($newName);
    }

    /**
     * @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}
     */
    private function range(Range $range): array
    {
        return [
            'start' => ['line' => $range->start()->line(), 'character' => $range->start()->character()],
            'end' => ['line' => $range->end()->line(), 'character' => $range->end()->character()],
        ];
    }
}
