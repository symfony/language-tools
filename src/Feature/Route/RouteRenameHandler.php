<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\RenameProviderInterface;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class RouteRenameHandler implements RenameProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly LspProtocolMapper $protocol,
        private readonly RouteSymbolResolver $symbolResolver,
        private readonly RouteReferenceIndexRegistry $referenceIndexes,
        private readonly RouteDeclarationIndexRegistry $declarationIndexes,
        private readonly RouteIndexRegistry $routeIndexes,
        private readonly ProjectPathResolver $pathResolver,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, placeholder: string}|null
     */
    public function prepare(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }

        [, $symbol] = $resolved;

        return [
            'range' => $this->protocol->range($symbol->range),
            'placeholder' => $symbol->name,
        ];
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{documentChanges: list<array{textDocument: array{uri: string, version: null}, edits: list<array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string, annotationId: string}>}>, changeAnnotations: array<string, array{label: string, needsConfirmation: bool, description: string}>}|null
     */
    public function rename(array $params): ?array
    {
        $newName = $params['newName'] ?? null;
        if (!\is_string($newName) || '' === $newName || str_contains($newName, "'") || str_contains($newName, '"')) {
            return null;
        }

        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }

        [$project, $symbol] = $resolved;
        if ($newName !== $symbol->name && null !== $this->routeIndexes->forProject($project)->get($newName)) {
            return null;
        }

        $declarations = $this->declarationIndexes->forProject($project)->find($symbol->name);
        if ([] === array_filter(
            $declarations,
            fn (RouteDeclaration $declaration): bool => $this->pathResolver->isApplicationOwned($project, $declaration->uri),
        )) {
            return null;
        }

        /** @var array<string, list<array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string, annotationId: string}>> $editsByUri */
        $editsByUri = [];
        foreach ($this->referenceIndexes->forProject($project)->find($symbol->name) as $reference) {
            if ($this->pathResolver->isApplicationOwned($project, $reference->uri)) {
                $editsByUri[$reference->uri][] = $this->edit($reference->range, $newName);
            }
        }
        foreach ($declarations as $declaration) {
            if ($this->pathResolver->isApplicationOwned($project, $declaration->uri)) {
                $editsByUri[$declaration->uri][] = $this->edit($declaration->range, $newName);
            }
        }
        ksort($editsByUri);

        $documentChanges = [];
        foreach ($editsByUri as $uri => $edits) {
            $documentChanges[] = [
                'textDocument' => ['uri' => $uri, 'version' => null],
                'edits' => $edits,
            ];
        }

        return [
            'documentChanges' => $documentChanges,
            'changeAnnotations' => [
                'routeRename' => [
                    'label' => \sprintf('Rename route "%s" to "%s"', $symbol->name, $newName),
                    'needsConfirmation' => true,
                    'description' => 'Dynamic route references may remain unchanged.',
                ],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{Project, RouteSymbol}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->documentContextResolver->resolvePositioned($params);
        if (null === $request
            || !$this->pathResolver->isApplicationOwned($request->project, $request->document->uri)
            || !\in_array($request->document->languageId, ['php', 'twig', 'yaml'], true)
        ) {
            return null;
        }

        $symbol = $this->symbolResolver->resolve($request->project, SourceDocument::fromDocument($request->document), $request->position);

        return null === $symbol ? null : [$request->project, $symbol];
    }

    /**
     * @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string, annotationId: string}
     */
    private function edit(Range $range, string $newName): array
    {
        return [
            'range' => $this->protocol->range($range),
            'newText' => $newName,
            'annotationId' => 'routeRename',
        ];
    }
}
