<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\RenameProviderInterface;

final class RouteRenameHandler implements RenameProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly RouteSymbolResolver $symbolResolver,
        private readonly RouteReferenceIndexRegistry $referenceIndexes,
        private readonly RouteDeclarationIndexRegistry $declarationIndexes,
        private readonly RouteIndexRegistry $routeIndexes,
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
            'range' => self::range($symbol->range()),
            'placeholder' => $symbol->name(),
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
        if ($newName !== $symbol->name() && null !== $this->routeIndexes->forProject($project)->get($newName)) {
            return null;
        }

        /** @var array<string, list<array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string, annotationId: string}>> $editsByUri */
        $editsByUri = [];
        foreach ($this->referenceIndexes->forProject($project)->find($symbol->name()) as $reference) {
            $editsByUri[$reference->uri()][] = self::edit($reference->range(), $newName);
        }
        foreach ($this->declarationIndexes->forProject($project)->find($symbol->name()) as $declaration) {
            $editsByUri[$declaration->uri()][] = self::edit($declaration->range(), $newName);
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
                    'label' => \sprintf('Rename route "%s" to "%s"', $symbol->name(), $newName),
                    'needsConfirmation' => true,
                    'description' => 'Dynamic route references may remain unchanged.',
                ],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{\Symfony\Lsp\Project\Project, RouteSymbol}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->documentContextResolver->resolve($params);
        if (null === $request) {
            return null;
        }

        [$document, $project, $position] = $request;
        if (!\in_array($document->languageId(), ['php', 'twig', 'yaml'], true)) {
            return null;
        }

        $symbol = $this->symbolResolver->resolve($document->uri(), $document->text(), $position);

        return null === $symbol ? null : [$project, $symbol];
    }

    /**
     * @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string, annotationId: string}
     */
    private static function edit(Range $range, string $newName): array
    {
        return [
            'range' => self::range($range),
            'newText' => $newName,
            'annotationId' => 'routeRename',
        ];
    }

    /**
     * @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}
     */
    private static function range(Range $range): array
    {
        return [
            'start' => [
                'line' => $range->start()->line(),
                'character' => $range->start()->character(),
            ],
            'end' => [
                'line' => $range->end()->line(),
                'character' => $range->end()->character(),
            ],
        ];
    }
}
