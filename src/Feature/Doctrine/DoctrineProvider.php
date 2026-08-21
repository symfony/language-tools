<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class DoctrineProvider implements CodeLensProviderInterface, CompletionProviderInterface, DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly DoctrineIndexRegistry $indexes,
        private readonly DoctrineExtractor $extractor,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        $context = $this->extractor->completionContext($request->document->languageId(), $request->document->text(), $offset);
        if (null === $context) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $entity = null !== $context->entityClass()
            ? $index->entity($context->entityClass())
            : $index->entityForRepository($context->repositoryClass() ?? '');
        if (null === $entity) {
            return [];
        }
        $items = [];
        foreach ($entity->fields() as $field) {
            if (!str_starts_with($field->name(), $context->prefix())) {
                continue;
            }
            $detail = $field->isAssociation() ? 'Doctrine association' : 'Doctrine field';
            if (null !== $field->type()) {
                $detail .= ' · '.$field->type();
            }
            $items[] = [
                'label' => $field->name(),
                'kind' => 10,
                'detail' => $detail,
                'textEdit' => $this->protocol->textEdit($context->range(), $field->name()),
            ];
        }

        return $items;
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
        $declarations = array_values(array_filter(
            $this->indexes->forProject($project)->relatedSymbols($symbol),
            static fn (DoctrineSourceSymbol $candidate): bool => $candidate->isDeclaration(),
        ));

        return array_map(fn (DoctrineSourceSymbol $candidate): array => $this->protocol->location($candidate->uri(), $candidate->range()), $declarations);
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;

        return array_map(fn (DoctrineSourceSymbol $candidate): array => $this->protocol->location($candidate->uri(), $candidate->range()), $this->indexes->forProject($project)->relatedSymbols($symbol));
    }

    public function codeLenses(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'php' !== $request->document->languageId()) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $facts = $this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text());
        $lenses = [];
        foreach ($facts->entities() as $entity) {
            $repository = null === $entity->repositoryClass() ? null : $index->repository($entity->repositoryClass());
            if (null !== $repository) {
                $lenses[] = $this->protocol->referenceLens($entity->range(), 'Repository: '.$repository->className(), $entity->uri(), [$this->protocol->location($repository->uri(), $repository->range())]);
            }
        }
        foreach ($facts->repositories() as $repository) {
            $entity = $index->entity($repository->entityClass());
            if (null !== $entity) {
                $lenses[] = $this->protocol->referenceLens($repository->range(), 'Entity: '.$entity->className(), $repository->uri(), [$this->protocol->location($entity->uri(), $entity->range())]);
            }
        }

        return $lenses;
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
