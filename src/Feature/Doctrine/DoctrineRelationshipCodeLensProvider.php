<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class DoctrineRelationshipCodeLensProvider implements CodeLensProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly LspProtocolMapper $protocol,
        private readonly DoctrineIndexRegistry $indexes,
        private readonly DoctrineExtractor $extractor,
    ) {
    }

    public function codeLenses(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'php' !== $request->document->languageId) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $facts = $this->extractor->extract($request->document->uri, $request->document->languageId, $request->document->text);
        $lenses = [];
        foreach ($facts->entities as $entity) {
            $repository = null === $entity->repositoryClass ? null : $index->repository($entity->repositoryClass);
            if (null !== $repository) {
                $lenses[] = $this->protocol->referenceLens($entity->range, 'Repository: '.$repository->className, $entity->uri, [$this->protocol->location($repository->uri, $repository->range)]);
            }
        }
        foreach ($facts->repositories as $repository) {
            $entity = $index->entity($repository->entityClass);
            if (null !== $entity) {
                $lenses[] = $this->protocol->referenceLens($repository->range, 'Entity: '.$entity->className, $repository->uri, [$this->protocol->location($entity->uri, $entity->range)]);
            }
        }

        return $lenses;
    }
}
