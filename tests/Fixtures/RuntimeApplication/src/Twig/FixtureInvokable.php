<?php

namespace App\Twig;

final class FixtureInvokable
{
    public function __invoke(string $value = ''): string
    {
        return $value;
    }
}
