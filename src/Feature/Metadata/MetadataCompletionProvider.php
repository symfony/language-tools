<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class MetadataCompletionProvider implements CompletionProviderInterface
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
        if (null === $context) {
            return null;
        }

        return match ($context->kind()) {
            MetadataCompletionKind::FormOption => $this->formOptions($context, $this->indexes->forProject($request->project)),
            MetadataCompletionKind::Constraint => $this->constraints($context, $this->indexes->forProject($request->project), $this->sourceIndexes->forProject($request->project)),
            MetadataCompletionKind::ConstraintOption => $this->constraintOptions($context, $this->indexes->forProject($request->project)),
            MetadataCompletionKind::SerializerGroup => $this->serializerGroups($context, $this->sourceIndexes->forProject($request->project)),
            MetadataCompletionKind::Property => $this->properties($context, $this->sourceIndexes->forProject($request->project)),
        };
    }

    /** @return list<array<array-key, mixed>> */
    private function formOptions(MetadataCompletionContext $context, MetadataIndex $index): array
    {
        $type = null === $context->owner() ? null : $index->formType($context->owner());
        if (null === $type) {
            return [];
        }
        $items = [];
        foreach ($type->options() as $option) {
            if (!str_starts_with($option, $context->prefix())) {
                continue;
            }
            $items[] = [
                'label' => $option,
                'detail' => \in_array($option, $type->requiredOptions(), true) ? 'Required form option' : 'Form option',
                'kind' => 14,
                'textEdit' => $this->protocol->textEdit($context->range(), $option),
            ];
        }

        return $items;
    }

    /** @return list<array<array-key, mixed>> */
    private function constraints(MetadataCompletionContext $context, MetadataIndex $index, MetadataSourceIndex $sourceIndex): array
    {
        $items = $this->constraintItems($index, $sourceIndex, $context);
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

    /** @return list<array<array-key, mixed>> */
    private function constraintOptions(MetadataCompletionContext $context, MetadataIndex $index): array
    {
        $constraint = null === $context->owner() ? null : $index->constraint($context->owner());
        if (null === $constraint) {
            return [];
        }
        $items = [];
        foreach ($constraint->options() as $option) {
            if (!str_starts_with($option, $context->prefix())) {
                continue;
            }
            $items[] = [
                'label' => $option,
                'detail' => 'Constraint option',
                'kind' => 14,
                'textEdit' => $this->protocol->textEdit($context->range(), $option),
            ];
        }

        return $items;
    }

    /** @return list<array<array-key, mixed>> */
    private function serializerGroups(MetadataCompletionContext $context, MetadataSourceIndex $sourceIndex): array
    {
        $items = [];
        foreach ($sourceIndex->names(MetadataSymbolKind::SerializerGroup) as $name) {
            if (!str_starts_with($name, $context->prefix())) {
                continue;
            }
            $items[] = [
                'label' => $name,
                'detail' => 'Serializer group',
                'kind' => 14,
                'textEdit' => $this->protocol->textEdit($context->range(), $name),
            ];
        }

        return $items;
    }

    /** @return list<array<array-key, mixed>> */
    private function properties(MetadataCompletionContext $context, MetadataSourceIndex $sourceIndex): array
    {
        $prefix = $context->owner().'::$';
        $names = [];
        foreach ($sourceIndex->symbols(MetadataSymbolKind::Property) as $symbol) {
            if ($symbol->isDeclaration() && str_starts_with($symbol->name(), $prefix)) {
                $names[substr($symbol->name(), \strlen($prefix))] = true;
            }
        }
        ksort($names);
        $items = [];
        foreach (array_keys($names) as $name) {
            if (!str_starts_with($name, $context->prefix())) {
                continue;
            }
            $items[] = [
                'label' => $name,
                'detail' => 'Mapped property',
                'kind' => 10,
                'textEdit' => $this->protocol->textEdit($context->range(), $name),
            ];
        }

        return $items;
    }

    /** @return list<array{label: string, detail: string}> */
    private function constraintItems(MetadataIndex $index, MetadataSourceIndex $sourceIndex, MetadataCompletionContext $context): array
    {
        if ([] !== $context->candidates()) {
            $items = [];
            foreach ($context->candidates() as $candidate) {
                if (null === $constraint = $index->constraint($candidate['class'])) {
                    continue;
                }
                $items[] = ['label' => $candidate['label'], 'detail' => $constraint->className()];
            }

            return $items;
        }
        if (null !== $context->owner()) {
            $constraint = $index->constraint($context->owner());

            return null === $constraint ? [] : [[
                'label' => $constraint->name(),
                'detail' => $constraint->className(),
            ]];
        }
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
}
