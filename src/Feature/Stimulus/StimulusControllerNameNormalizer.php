<?php

namespace Symfony\Lsp\Feature\Stimulus;

final class StimulusControllerNameNormalizer
{
    public function normalize(string $name): string
    {
        $name = str_replace('/', '--', $name);
        $name = str_replace('_', '-', $name);

        return str_starts_with($name, '@') ? substr($name, 1) : $name;
    }
}
