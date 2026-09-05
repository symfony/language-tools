<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpTypedVariable;

final class ConsoleInputReceiverResolver
{
    private const INPUT_INTERFACE = 'Symfony\\Component\\Console\\Input\\InputInterface';

    public function hasInputReceiver(PhpDocument $php, PhpMethodCall $call): bool
    {
        return array_any($php->receiverVariables($call), self::isInputVariable(...));
    }

    private static function isInputVariable(PhpTypedVariable $variable): bool
    {
        return \in_array(self::INPUT_INTERFACE, $variable->types, true);
    }
}
