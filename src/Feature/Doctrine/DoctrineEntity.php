<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\Range;

final class DoctrineEntity
{
    /** @param list<DoctrineField> $fields */
    public function __construct(
        private readonly string $className,
        private readonly string $uri,
        private readonly Range $range,
        private readonly ?string $repositoryClass,
        private readonly array $fields,
    ) {
    }

    public function className(): string
    {
        return $this->className;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function range(): Range
    {
        return $this->range;
    }

    public function repositoryClass(): ?string
    {
        return $this->repositoryClass;
    }

    /** @return list<DoctrineField> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function field(string $name): ?DoctrineField
    {
        foreach ($this->fields as $field) {
            if ($name === $field->name()) {
                return $field;
            }
        }

        return null;
    }
}
