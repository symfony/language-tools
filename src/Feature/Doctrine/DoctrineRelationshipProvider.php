<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\ReferencesRequest;

final class DoctrineRelationshipProvider implements DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly PositionedSourceSymbolResolver $positionedSymbols,
        private readonly LspProtocolMapper $protocol,
        private readonly DoctrineIndexRegistry $indexes,
        private readonly DoctrineExtractor $extractor,
    ) {
    }

    public function hover(PositionedRequest $request): ?array
    {
        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        if (DoctrineSymbolKind::Field === $symbol->kind) {
            $entity = $this->entityForSymbol($index, $symbol);
            $field = $entity?->field($symbol->name);
            if (null === $entity || null === $field) {
                return null;
            }
            $details = [\sprintf('Doctrine %s: `%s::$%s`', $field->association ? 'association' : 'field', $entity->className, $field->name)];
            if (null !== $field->type) {
                $details[] = 'Type: `'.$field->type.'`';
            }
            if (null !== $field->targetEntity) {
                $details[] = 'Target entity: `'.$field->targetEntity.'`';
            }

            return $this->protocol->markdownHover(implode("\n\n", $details));
        }
        if (DoctrineSymbolKind::Entity === $symbol->kind) {
            $entity = $index->entity($symbol->name);
            if (null === $entity) {
                return null;
            }
            $details = ['Doctrine entity: `'.$entity->className.'`'];
            if (null !== $entity->repositoryClass) {
                $details[] = 'Repository: `'.$entity->repositoryClass.'`';
            }
            $details[] = \sprintf('%d mapped field%s', \count($entity->fields), 1 === \count($entity->fields) ? '' : 's');

            return $this->protocol->markdownHover(implode("\n\n", $details));
        }
        $repository = $index->repository($symbol->name);

        return null === $repository ? null : $this->protocol->markdownHover(\sprintf("Doctrine repository: `%s`\n\nEntity: `%s`", $repository->className, $repository->entityClass));
    }

    public function definition(PositionedRequest $request): array
    {
        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return [];
        }
        [$symbol, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        $locations = [];
        foreach ($index->relatedSymbols($symbol) as $candidate) {
            if ($candidate->declaration) {
                $locations[] = $this->protocol->location($candidate->uri, $candidate->range);
            }
        }
        if ([] === $locations) {
            // runtime-only entities, such as XML mappings or vendor classes,
            // have no source declaration symbols
            if (DoctrineSymbolKind::Field === $symbol->kind && null !== $symbol->owner) {
                $entity = $index->entity($symbol->owner) ?? $index->entityForRepository($symbol->owner);
                $field = $entity?->field($symbol->name);
                if (null !== $field) {
                    $locations[] = $this->protocol->location($field->uri, $field->range);
                }
            } elseif (DoctrineSymbolKind::Entity === $symbol->kind) {
                $entity = $index->entity($symbol->name);
                if (null !== $entity) {
                    $locations[] = $this->protocol->location($entity->uri, $entity->range);
                }
            }
        }

        return $locations;
    }

    public function references(ReferencesRequest $request): array
    {
        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return [];
        }
        [$symbol, $project] = $resolved;

        return $this->protocol->locations($request->reported($this->indexes->forProject($project)->relatedSymbols($symbol)));
    }

    /** @return array{DoctrineSourceSymbol, Project}|null */
    private function resolve(PositionedRequest $request): ?array
    {
        $document = $request->source;
        $symbol = $this->positionedSymbols->resolve($document, $request->position, $this->extractor->extract($document)->symbols);

        return null === $symbol ? null : [$symbol, $request->project];
    }

    private function entityForSymbol(DoctrineIndex $index, DoctrineSourceSymbol $symbol): ?DoctrineEntity
    {
        $owner = $symbol->owner;
        if (null === $owner) {
            return null;
        }

        return $index->entity($owner) ?? $index->entityForRepository($owner);
    }
}
