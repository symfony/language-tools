<?php

namespace App\Shorthand;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Mimics bundles such as DoctrineBundle whose configuration relocates
 * shorthand keys into a prototyped child during normalization.
 */
final class FixtureShorthandBundle extends Bundle
{
}
