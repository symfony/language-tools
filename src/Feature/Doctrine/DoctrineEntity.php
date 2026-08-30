<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\Range;

final class DoctrineEntity
{
    /** @param list<DoctrineField> $fields */
    public function __construct(
        public readonly string $className,
        public readonly string $uri,
        public readonly Range $range,
        public readonly ?string $repositoryClass,
        public readonly array $fields,
    ) {
    }

    public function field(string $name): ?DoctrineField
    {
        foreach ($this->fields as $field) {
            if ($name === $field->name) {
                return $field;
            }
        }

        return null;
    }
}
