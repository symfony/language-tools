<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Feature\RenameEditBuilder;
use Symfony\Lsp\Feature\RenameProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\RenameRequest;

final class RouteRenameHandler implements RenameProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly RouteSymbolResolver $symbolResolver,
        private readonly RouteSourceIndexRegistry $sourceIndexes,
        private readonly RouteIndexRegistry $routeIndexes,
        private readonly ProjectPathResolver $pathResolver,
        private readonly RenameEditBuilder $editBuilder,
    ) {
    }

    /** @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, placeholder: string}|null */
    public function prepare(PositionedRequest $request): ?array
    {
        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return null;
        }

        [$project, $symbol] = $resolved;
        if ([] === $this->applicationDeclarations($project, $symbol->name)) {
            return null;
        }

        return [
            'range' => $this->protocol->range($symbol->range),
            'placeholder' => $symbol->name,
        ];
    }

    /** @return array{documentChanges: list<array{textDocument: array{uri: string, version: null}, edits: list<array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string, annotationId: string}>}>, changeAnnotations: array<string, array{label: string, needsConfirmation: bool, description: string}>}|null */
    public function rename(RenameRequest $request): ?array
    {
        $newName = $request->newName;
        if (str_contains($newName, "'") || str_contains($newName, '"')) {
            return null;
        }

        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return null;
        }

        [$project, $symbol] = $resolved;
        if ($newName !== $symbol->name && null !== $this->routeIndexes->forProject($project)->get($newName)) {
            return null;
        }

        $sourceIndex = $this->sourceIndexes->forProject($project);
        $declarations = $this->applicationDeclarations($project, $symbol->name);
        if ([] === $declarations) {
            return null;
        }

        $locations = [];
        foreach ($sourceIndex->references($symbol->name) as $reference) {
            if ($this->pathResolver->isApplicationOwned($project, $reference->uri)) {
                $locations[] = [$reference->uri, $reference->range, $newName];
            }
        }
        foreach ($declarations as $declaration) {
            $locations[] = [$declaration->uri, $declaration->range, $newName];
        }

        return [
            'documentChanges' => $this->editBuilder->documentChanges($locations, 'routeRename'),
            'changeAnnotations' => [
                'routeRename' => [
                    'label' => \sprintf('Rename route "%s" to "%s"', $symbol->name, $newName),
                    'needsConfirmation' => true,
                    'description' => 'Dynamic route references may remain unchanged.',
                ],
            ],
        ];
    }

    /** @return array{Project, RouteSymbol}|null */
    private function resolve(PositionedRequest $request): ?array
    {
        if (!$this->pathResolver->isApplicationOwned($request->project, $request->document->uri)
            || !\in_array($request->document->languageId, ['php', 'twig', 'yaml'], true)
        ) {
            return null;
        }

        $symbol = $this->symbolResolver->resolve($request);

        return null === $symbol ? null : [$request->project, $symbol];
    }

    /** @return list<RouteDeclaration> */
    private function applicationDeclarations(Project $project, string $name): array
    {
        return array_values(array_filter(
            $this->sourceIndexes->forProject($project)->declarations($name),
            fn (RouteDeclaration $declaration): bool => $this->pathResolver->isApplicationOwned($project, $declaration->uri),
        ));
    }
}
