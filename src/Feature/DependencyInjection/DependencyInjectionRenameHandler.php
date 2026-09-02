<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\RenameProviderInterface;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class DependencyInjectionRenameHandler implements RenameProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly LspProtocolMapper $protocol,
        private readonly DependencyInjectionSymbolResolver $symbolResolver,
        private readonly DependencyInjectionSourceIndexRegistry $sourceIndexes,
        private readonly DependencyInjectionProjectLookup $lookup,
        private readonly ProjectPathResolver $pathResolver,
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
            'range' => $this->protocol->range($symbol->range),
            'placeholder' => $symbol->name,
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
            || !$this->validName($symbol->kind, $newName)
            || $this->exists($project, $symbol, $newName)
        ) {
            return null;
        }

        $index = $this->sourceIndexes->forProject($project);
        $locations = [];
        foreach ($index->references($symbol->kind, $symbol->name) as $reference) {
            if ($this->pathResolver->isApplicationOwned($project, $reference->uri)) {
                $locations[] = [$reference->uri, $reference->range];
            }
        }
        foreach ($this->lookup->declarations($project, $symbol->kind, $symbol->name) as $declaration) {
            if ($this->pathResolver->isApplicationOwned($project, $declaration->uri)) {
                $locations[] = [$declaration->uri, $declaration->range];
            }
        }

        $editsByUri = [];
        foreach ($locations as [$uri, $range]) {
            $key = $uri.'\0'.$range->start->line.'\0'.$range->start->character;
            $editsByUri[$uri][$key] = [
                'range' => $this->protocol->range($range),
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

        $kind = DependencyInjectionSymbolKind::Service === $symbol->kind ? 'service' : 'parameter';

        return [
            'documentChanges' => $documentChanges,
            'changeAnnotations' => [
                'dependencyInjectionRename' => [
                    'label' => \sprintf('Rename %s "%s" to "%s"', $kind, $symbol->name, $newName),
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
        $request = $this->documentContextResolver->resolvePositioned($params);
        if (null === $request || !$this->pathResolver->isApplicationOwned($request->project, $request->document->uri)) {
            return null;
        }
        $symbol = $this->symbolResolver->resolve(
            SourceDocument::fromDocument($request->document),
            $request->position,
        );

        return null === $symbol ? null : [$request->project, $symbol];
    }

    private function isApplicationOwned(Project $project, DependencyInjectionSymbol $symbol): bool
    {
        return [] !== array_filter(
            $this->lookup->declarations($project, $symbol->kind, $symbol->name),
            fn (ServiceDeclaration|ParameterDeclaration $declaration): bool => $this->pathResolver->isApplicationOwned($project, $declaration->uri),
        );
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
        if ($newName === $symbol->name) {
            return false;
        }

        return $this->lookup->hasNameCollision($project, $symbol->kind, $newName);
    }
}
