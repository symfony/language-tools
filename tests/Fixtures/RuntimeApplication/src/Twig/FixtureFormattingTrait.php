<?php

namespace App\Twig;

trait FixtureFormattingTrait
{
    public function shout(string $value): string
    {
        return strtoupper($value);
    }
}
