<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<StimulusSourceFacts> */
final class StimulusSourceIndex extends AbstractSourceFactsIndex
{
    /** @return list<StimulusControllerDeclaration> */
    public function declarations(?string $name = null): array
    {
        $declarations = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations() as $declaration) {
                if (null === $name || $name === $declaration->name()) {
                    $declarations[] = $declaration;
                }
            }
        }

        return $declarations;
    }

    /** @return list<StimulusReference> */
    public function references(string $controller, ?StimulusMemberKind $kind = null, ?string $member = null): array
    {
        $references = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->references() as $reference) {
                if ($controller === $reference->controller()
                    && $kind === $reference->kind()
                    && $member === $reference->member()
                ) {
                    $references[] = $reference;
                }
            }
        }

        return $references;
    }
}
