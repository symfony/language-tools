<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<StimulusSourceFacts> */
final class StimulusSourceIndex extends AbstractSourceFactsIndex
{
    /** @var list<StimulusControllerDeclaration> */
    private array $declarations = [];

    /** @var array<string, list<StimulusControllerDeclaration>> */
    private array $declarationsByName = [];

    /** @var array<string, array<string, list<StimulusReference>>> */
    private array $references = [];

    /** @return list<StimulusControllerDeclaration> */
    public function declarations(?string $name = null): array
    {
        $this->derive();

        return null === $name ? $this->declarations : $this->declarationsByName[$name] ?? [];
    }

    /** @return list<StimulusReference> */
    public function references(string $controller, ?StimulusMemberKind $kind = null, ?string $member = null): array
    {
        $this->derive();

        return $this->references[$controller][$this->referenceKey($kind, $member)] ?? [];
    }

    protected function build(): void
    {
        $this->declarations = [];
        $this->declarationsByName = [];
        $this->references = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations as $declaration) {
                $this->declarations[] = $declaration;
                $this->declarationsByName[$declaration->name][] = $declaration;
            }
            foreach ($facts->references as $reference) {
                $this->references[$reference->controller][$this->referenceKey($reference->kind, $reference->member)][] = $reference;
            }
        }
    }

    private function referenceKey(?StimulusMemberKind $kind, ?string $member): string
    {
        return (null === $kind ? "\0" : $kind->value)."\0".(null === $member ? "\0" : 's'.$member);
    }
}
