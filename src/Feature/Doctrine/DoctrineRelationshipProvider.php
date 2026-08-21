<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class DoctrineRelationshipProvider implements DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly DoctrineIndexRegistry $indexes,
        private readonly DoctrineExtractor $extractor,
    ) {
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        if (DoctrineSymbolKind::Field === $symbol->kind()) {
            $entity = $this->entityForSymbol($index, $symbol);
            $field = $entity?->field($symbol->name());
            if (null === $entity || null === $field) {
                return null;
            }
            $details = [\sprintf('Doctrine %s: `%s::$%s`', $field->isAssociation() ? 'association' : 'field', $entity->className(), $field->name())];
            if (null !== $field->type()) {
                $details[] = 'Type: `'.$field->type().'`';
            }
            if (null !== $field->targetEntity()) {
                $details[] = 'Target entity: `'.$field->targetEntity().'`';
            }

            return $this->protocol->markdownHover(implode("\n\n", $details));
        }
        if (DoctrineSymbolKind::Entity === $symbol->kind()) {
            $entity = $index->entity($symbol->name());
            if (null === $entity) {
                return null;
            }
            $details = ['Doctrine entity: `'.$entity->className().'`'];
            if (null !== $entity->repositoryClass()) {
                $details[] = 'Repository: `'.$entity->repositoryClass().'`';
            }
            $details[] = \sprintf('%d mapped field%s', \count($entity->fields()), 1 === \count($entity->fields()) ? '' : 's');

            return $this->protocol->markdownHover(implode("\n\n", $details));
        }
        $repository = $index->repository($symbol->name());

        return null === $repository ? null : $this->protocol->markdownHover(\sprintf("Doctrine repository: `%s`\n\nEntity: `%s`", $repository->className(), $repository->entityClass()));
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;
        $locations = [];
        foreach ($this->indexes->forProject($project)->relatedSymbols($symbol) as $candidate) {
            if ($candidate->isDeclaration()) {
                $locations[] = $this->protocol->location($candidate->uri(), $candidate->range());
            }
        }

        return $locations;
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;
        $locations = [];
        foreach ($this->indexes->forProject($project)->relatedSymbols($symbol) as $candidate) {
            $locations[] = $this->protocol->location($candidate->uri(), $candidate->range());
        }

        return $locations;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{DoctrineSourceSymbol, Project}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        foreach ($this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text())->symbols() as $symbol) {
            if ($this->contains($request->document, $symbol->range(), $offset)) {
                return [$symbol, $request->project];
            }
        }

        return null;
    }

    private function entityForSymbol(DoctrineIndex $index, DoctrineSourceSymbol $symbol): ?DoctrineEntity
    {
        $owner = $symbol->owner();
        if (null === $owner) {
            return null;
        }

        return $index->entity($owner) ?? $index->entityForRepository($owner);
    }

    private function contains(Document $document, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($document->text(), $range->start())
            && $offset <= $this->converter->toByteOffset($document->text(), $range->end());
    }
}
