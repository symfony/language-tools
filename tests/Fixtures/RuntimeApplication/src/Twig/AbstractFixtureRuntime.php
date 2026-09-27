<?php

namespace App\Twig;

use Twig\Environment;

abstract class AbstractFixtureRuntime
{
    public function label(Environment $environment, string $value): string
    {
        return $value;
    }
}
