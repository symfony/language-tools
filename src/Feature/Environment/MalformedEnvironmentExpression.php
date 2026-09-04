<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\Range;

final class MalformedEnvironmentExpression
{
    public function __construct(public readonly Range $range)
    {
    }
}
