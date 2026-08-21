<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class ValidationMetadataProvider implements CompletionProviderInterface, DiagnosticProviderInterface, HoverProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly MetadataIndexRegistry $indexes,
        private readonly MetadataSourceIndexRegistry $sourceIndexes,
        private readonly MetadataExtractor $extractor,
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
        if (null === $context || !\in_array($context->kind(), [MetadataCompletionKind::Constraint, MetadataCompletionKind::ConstraintOption], true)) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $items = MetadataCompletionKind::Constraint === $context->kind()
            ? $this->constraintItems($index, $this->sourceIndexes->forProject($request->project))
            : $this->constraintOptionItems($index, $context);
        $completion = [];
        foreach ($items as $item) {
            if (!str_starts_with($item['label'], $context->prefix())) {
                continue;
            }
            $completion[] = [
                ...$item,
                'kind' => 14,
                'textEdit' => $this->protocol->textEdit($context->range(), $item['label']),
            ];
        }

        return $completion;
    }

    public function hover(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        $constraintOptions = 'yaml' === $request->document->languageId()
            ? $this->extractor->yamlConstraintOptions($request->document->text())
            : $this->extractor->constraintOptions($request->document->text());
        foreach ($constraintOptions as $option) {
            if (!$this->contains($request->document, $option['range'], $offset)) {
                continue;
            }
            $constraint = $this->indexes->forProject($request->project)->constraint($option['constraint']);

            return null === $constraint ? null : $this->protocol->markdownHover(\sprintf("Constraint option: `%s`\n\nConstraint: `%s`", $option['option'], $constraint->className()));
        }

        return null;
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || !\in_array($request->document->languageId(), ['php', 'yaml'], true)) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $constraintOptions = 'yaml' === $request->document->languageId()
            ? $this->extractor->yamlConstraintOptions($request->document->text())
            : $this->extractor->constraintOptions($request->document->text());
        $diagnostics = [];
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
    private function constraintOptionItems(MetadataIndex $index, MetadataCompletionContext $context): array
    {
        $constraint = null === $context->owner() ? null : $index->constraint($context->owner());
        if (null === $constraint) {
            return [];
        }
        $items = [];
        foreach ($constraint->options() as $option) {
            $items[] = ['label' => $option, 'detail' => 'Constraint option'];
        }

        return $items;
    }

    private function contains(Document $document, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($document->text(), $range->start())
            && $offset <= $this->converter->toByteOffset($document->text(), $range->end());
    }

    /** @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, severity: int, source: string, code: string, message: string} */
    private function diagnostic(Range $range, string $code, string $message): array
    {
        return $this->protocol->diagnostic($range, 1, $code, $message);
    }
}
