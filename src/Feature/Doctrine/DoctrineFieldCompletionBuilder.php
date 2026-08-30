<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Protocol\LspProtocolMapper;

final class DoctrineFieldCompletionBuilder
{
    public function __construct(private readonly LspProtocolMapper $protocol)
    {
    }

    /** @return list<array<array-key, mixed>> */
    public function build(DoctrineCompletionContext $context, DoctrineIndex $index): array
    {
        $entity = null !== $context->entityClass
            ? $index->entity($context->entityClass)
            : $index->entityForRepository($context->repositoryClass ?? '');
        if (null === $entity) {
            return [];
        }
        $items = [];
        foreach ($entity->fields as $field) {
            if (!str_starts_with($field->name, $context->prefix)) {
                continue;
            }
            $detail = $field->association ? 'Doctrine association' : 'Doctrine field';
            if (null !== $field->type) {
                $detail .= ' · '.$field->type;
            }
            $items[] = [
                'label' => $field->name,
                'kind' => 10,
                'detail' => $detail,
                'textEdit' => $this->protocol->textEdit($context->range, $field->name),
            ];
        }

        return $items;
    }
}
