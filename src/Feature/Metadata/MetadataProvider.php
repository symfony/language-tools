<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class MetadataProvider implements CompletionProviderInterface, DefinitionProviderInterface, DiagnosticProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly PositionConverter $converter,
        private readonly MetadataIndexRegistry $indexes,
        private readonly MetadataSourceIndexRegistry $sourceIndexes,
        private readonly MetadataExtractor $extractor,
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
        $sourceIndex = $this->sourceIndexes->forProject($project);
        $items = match ($context->kind()) {
            MetadataCompletionKind::FormOption => $this->formOptionItems($index, $context),
            MetadataCompletionKind::Constraint => $this->constraintItems($index, $sourceIndex),
            MetadataCompletionKind::ConstraintOption => $this->constraintOptionItems($index, $context),
            MetadataCompletionKind::SerializerGroup => array_map(
                static fn (string $name): array => ['label' => $name, 'detail' => 'Serializer group'],
                $sourceIndex->names(MetadataSymbolKind::SerializerGroup),
            ),
            MetadataCompletionKind::Property => $this->propertyItems($sourceIndex, $context),
        };
        $completion = [];
        foreach ($items as $item) {
            $label = $item['label'];
            if (!str_starts_with($label, $context->prefix())) {
                continue;
            }
            $completion[] = [
                ...$item,
                'kind' => MetadataCompletionKind::Property === $context->kind() ? 10 : 14,
                'textEdit' => ['range' => $this->range($context->range()), 'newText' => $label],
            ];
        }

        return $completion;
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->resolveSourceSymbol($params);
        if (null !== $resolved) {
            [$symbol, $project] = $resolved;
            $count = \count($this->sourceIndexes->forProject($project)->symbols($symbol->kind(), $symbol->name()));
            $value = match ($symbol->kind()) {
                MetadataSymbolKind::Constraint => 'Validation constraint: `'.$symbol->name().'`',
                MetadataSymbolKind::MappedClass => 'Mapped class: `'.$symbol->name().'`',
                MetadataSymbolKind::Property => 'Mapped property: `'.$symbol->name().'`',
                MetadataSymbolKind::SerializerGroup => \sprintf("Serializer group: `%s`\n\n%d known occurrence%s", $symbol->name(), $count, 1 === $count ? '' : 's'),
            };

            return ['contents' => ['kind' => 'markdown', 'value' => $value]];
        }
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        $offset = $this->converter->toByteOffset($document->text(), $position);
        foreach ($this->extractor->formOptions($document->text()) as $option) {
            if (!$this->contains($document, $option['range'], $offset)) {
                continue;
            }
            $type = $this->indexes->forProject($project)->formType($option['class']);
            if (null === $type || !\in_array($option['option'], $type->options(), true)) {
                return null;
            }
            $required = \in_array($option['option'], $type->requiredOptions(), true);

            return ['contents' => ['kind' => 'markdown', 'value' => \sprintf("Form option: `%s`\n\nType: `%s`\n\nRequired: %s", $option['option'], $type->className(), $required ? 'yes' : 'no')]];
        }
        $constraintOptions = 'yaml' === $document->languageId()
            ? $this->extractor->yamlConstraintOptions($document->text())
            : $this->extractor->constraintOptions($document->text());
        foreach ($constraintOptions as $option) {
            if (!$this->contains($document, $option['range'], $offset)) {
                continue;
            }
            $constraint = $this->indexes->forProject($project)->constraint($option['constraint']);

            return null === $constraint ? null : ['contents' => ['kind' => 'markdown', 'value' => \sprintf("Constraint option: `%s`\n\nConstraint: `%s`", $option['option'], $constraint->className())]];
        }

        return null;
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolveSourceSymbol($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;
        $declarations = array_values(array_filter(
            $this->sourceIndexes->forProject($project)->symbols($symbol->kind(), $symbol->name()),
            static fn (MetadataSourceSymbol $candidate): bool => $candidate->isDeclaration(),
        ));

        return array_map(fn (MetadataSourceSymbol $candidate): array => ['uri' => $candidate->uri(), 'range' => $this->range($candidate->range())], $declarations);
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolveSourceSymbol($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;

        return array_map(fn (MetadataSourceSymbol $candidate): array => ['uri' => $candidate->uri(), 'range' => $this->range($candidate->range())], $this->sourceIndexes->forProject($project)->symbols($symbol->kind(), $symbol->name()));
    }

    public function diagnostics(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }
        $document = $this->documents->get($textDocument['uri']);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $document || null === $project || !\in_array($document->languageId(), ['php', 'yaml'], true)) {
            return null;
        }
        $index = $this->indexes->forProject($project);
        $diagnostics = [];
        if ('php' === $document->languageId()) {
            foreach ($this->extractor->formOptions($document->text()) as $option) {
                $type = $index->formType($option['class']);
                if (null !== $type && !\in_array($option['option'], $type->options(), true)) {
                    $diagnostics[] = $this->diagnostic($option['range'], 'form.unknown_option', \sprintf('Unknown option "%s" for form type "%s".', $option['option'], $type->className()));
                }
            }
        }
        $constraintOptions = 'yaml' === $document->languageId()
            ? $this->extractor->yamlConstraintOptions($document->text())
            : $this->extractor->constraintOptions($document->text());
        foreach ($constraintOptions as $option) {
            $constraint = $index->constraint($option['constraint']);
            if (null !== $constraint && !\in_array($option['option'], $constraint->options(), true)) {
                $diagnostics[] = $this->diagnostic($option['range'], 'validation.unknown_constraint_option', \sprintf('Unknown option "%s" for constraint "%s".', $option['option'], $constraint->name()));
            }
        }

        return $diagnostics;
    }

    /** @return list<array{label: string, detail: string}> */
    private function constraintItems(MetadataIndex $index, MetadataSourceIndex $sourceIndex): array
    {
        $items = [];
        foreach ($index->constraints() as $constraint) {
            $items[$constraint->name()] = ['label' => $constraint->name(), 'detail' => $constraint->className()];
        }
        foreach ($sourceIndex->names(MetadataSymbolKind::Constraint) as $name) {
            $items[$name] ??= ['label' => $name, 'detail' => 'Validation constraint'];
        }
        ksort($items);

        return array_values($items);
    }

    /** @return list<array{label: string, detail: string}> */
    private function formOptionItems(MetadataIndex $index, MetadataCompletionContext $context): array
    {
        $type = null === $context->owner() ? null : $index->formType($context->owner());
        if (null === $type) {
            return [];
        }

        return array_map(static fn (string $option): array => [
            'label' => $option,
            'detail' => \in_array($option, $type->requiredOptions(), true) ? 'Required form option' : 'Form option',
        ], $type->options());
    }

    /** @return list<array{label: string, detail: string}> */
    private function constraintOptionItems(MetadataIndex $index, MetadataCompletionContext $context): array
    {
        $constraint = null === $context->owner() ? null : $index->constraint($context->owner());
        if (null === $constraint) {
            return [];
        }

        return array_map(static fn (string $option): array => ['label' => $option, 'detail' => 'Constraint option'], $constraint->options());
    }

    /** @return list<array{label: string, detail: string}> */
    private function propertyItems(MetadataSourceIndex $index, MetadataCompletionContext $context): array
    {
        $prefix = $context->owner().'::$';
        $items = [];
        foreach ($index->symbols(MetadataSymbolKind::Property) as $symbol) {
            if ($symbol->isDeclaration() && str_starts_with($symbol->name(), $prefix)) {
                $items[substr($symbol->name(), \strlen($prefix))] = true;
            }
        }
        ksort($items);

        return array_map(static fn (string $name): array => ['label' => $name, 'detail' => 'Mapped property'], array_keys($items));
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{MetadataSourceSymbol, Project}|null
     */
    private function resolveSourceSymbol(array $params): ?array
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

    private function contains(Document $document, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($document->text(), $range->start())
            && $offset <= $this->converter->toByteOffset($document->text(), $range->end());
    }

    /** @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, severity: int, source: string, code: string, message: string} */
    private function diagnostic(Range $range, string $code, string $message): array
    {
        return ['range' => $this->range($range), 'severity' => 1, 'source' => 'symfony', 'code' => $code, 'message' => $message];
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    private function range(Range $range): array
    {
        return ['start' => ['line' => $range->start()->line(), 'character' => $range->start()->character()], 'end' => ['line' => $range->end()->line(), 'character' => $range->end()->character()]];
    }
}
