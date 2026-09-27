<?php

namespace App\Twig;

use Twig\Extension\RuntimeExtensionInterface;

final class FixtureRuntime extends AbstractFixtureRuntime implements RuntimeExtensionInterface
{
    use FixtureFormattingTrait;

    /**
     * @param array<string, mixed>                  $context
     * @param array{prefix?: string, upper?: bool} $options
     */
    public function format(array $context, string $value, array $options = []): string
    {
        return ($options['prefix'] ?? '').$value;
    }
}
