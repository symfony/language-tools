<?php

namespace Symfony\Lsp\Feature\Metadata;

final class MetadataIndex
{
    /** @var array<string, FormType> */
    private array $formTypes = [];
    /** @var array<string, ValidationConstraint> */
    private array $constraints = [];
    private bool $formsComplete = false;
    private bool $constraintsComplete = false;

    /**
     * @param list<FormType>             $formTypes
     * @param list<ValidationConstraint> $constraints
     */
    public function replace(array $formTypes, array $constraints, bool $formsComplete, bool $constraintsComplete): void
    {
        $this->formTypes = [];
        foreach ($formTypes as $formType) {
            $this->formTypes[$formType->className()] = $formType;
        }
        ksort($this->formTypes);
        $this->constraints = [];
        foreach ($constraints as $constraint) {
            $this->constraints[$constraint->name()] = $constraint;
        }
        ksort($this->constraints);
        $this->formsComplete = $formsComplete;
        $this->constraintsComplete = $constraintsComplete;
    }

    /** @return list<FormType> */
    public function formTypes(): array
    {
        return array_values($this->formTypes);
    }

    public function formType(string $className): ?FormType
    {
        return $this->formTypes[ltrim($className, '\\')] ?? null;
    }

    /** @return list<ValidationConstraint> */
    public function constraints(): array
    {
        return array_values($this->constraints);
    }

    public function constraint(string $name): ?ValidationConstraint
    {
        return $this->constraints[$name] ?? null;
    }

    public function formsComplete(): bool
    {
        return $this->formsComplete;
    }

    public function constraintsComplete(): bool
    {
        return $this->constraintsComplete;
    }
}
