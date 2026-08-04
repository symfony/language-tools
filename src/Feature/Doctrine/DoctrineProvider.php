<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class DoctrineProvider implements CodeLensProviderInterface, CompletionProviderInterface, DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly PositionConverter $converter,
        private readonly DoctrineIndexRegistry $indexes,
        private readonly DoctrineExtractor $extractor,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        $offset = $this->converter->toByteOffset($document->text(), $position);
        $context = $this->extractor->completionContext($document->languageId(), $document->text(), $offset);
        if (null === $context) {
            return null;
        }
        $index = $this->indexes->forProject($project);
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
                'textEdit' => ['range' => $this->range($context->range()), 'newText' => $field->name()],
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

            return ['contents' => ['kind' => 'markdown', 'value' => implode("\n\n", $details)]];
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

            return ['contents' => ['kind' => 'markdown', 'value' => implode("\n\n", $details)]];
        }
        $repository = $index->repository($symbol->name());

        return null === $repository ? null : ['contents' => ['kind' => 'markdown', 'value' => \sprintf("Doctrine repository: `%s`\n\nEntity: `%s`", $repository->className(), $repository->entityClass())]];
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

        return array_map(fn (DoctrineSourceSymbol $candidate): array => ['uri' => $candidate->uri(), 'range' => $this->range($candidate->range())], $declarations);
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;

        return array_map(fn (DoctrineSourceSymbol $candidate): array => ['uri' => $candidate->uri(), 'range' => $this->range($candidate->range())], $this->indexes->forProject($project)->relatedSymbols($symbol));
    }

    public function codeLenses(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }
        $document = $this->documents->get($textDocument['uri']);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $document || null === $project || 'php' !== $document->languageId()) {
            return null;
        }
        $index = $this->indexes->forProject($project);
        $facts = $this->extractor->extract($document->uri(), $document->languageId(), $document->text());
        $lenses = [];
        foreach ($facts->entities() as $entity) {
            $repository = null === $entity->repositoryClass() ? null : $index->repository($entity->repositoryClass());
            if (null !== $repository) {
                $lenses[] = $this->relationLens($entity->range(), $entity->uri(), 'Repository: '.$repository->className(), $repository->uri(), $repository->range());
            }
        }
        foreach ($facts->repositories() as $repository) {
            $entity = $index->entity($repository->entityClass());
            if (null !== $entity) {
                $lenses[] = $this->relationLens($repository->range(), $repository->uri(), 'Entity: '.$entity->className(), $entity->uri(), $entity->range());
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
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        $offset = $this->converter->toByteOffset($document->text(), $position);
        foreach ($this->extractor->extract($document->uri(), $document->languageId(), $document->text())->symbols() as $symbol) {
            if ($this->contains($document, $symbol->range(), $offset)) {
                return [$symbol, $project];
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

    /** @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, command: array{title: string, command: string, arguments: array{string, array{line: int, character: int}, list<array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}>}}} */
    private function relationLens(Range $range, string $uri, string $title, string $targetUri, Range $targetRange): array
    {
        return [
            'range' => $this->range($range),
            'command' => [
                'title' => $title,
                'command' => 'editor.action.showReferences',
                'arguments' => [
                    $uri,
                    $this->range($range)['start'],
                    [['uri' => $targetUri, 'range' => $this->range($targetRange)]],
                ],
            ],
        ];
    }

    private function contains(Document $document, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($document->text(), $range->start())
            && $offset <= $this->converter->toByteOffset($document->text(), $range->end());
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    private function range(Range $range): array
    {
        return ['start' => ['line' => $range->start()->line(), 'character' => $range->start()->character()], 'end' => ['line' => $range->end()->line(), 'character' => $range->end()->character()]];
    }
}
