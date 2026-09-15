<?php

namespace Symfony\Lsp\Parser\Php;

/**
 * What a file says about the type of a method call receiver.
 *
 * A feature that drives diagnostics accepts `Matches` only; a feature that
 * only adds navigation may also accept `Unknown`, at the price of acting on
 * receivers this file cannot type.
 */
enum PhpReceiverMatch
{
    /** The receiver is declared as one of the expected types. */
    case Matches;

    /** The receiver is declared, as none of the expected types. */
    case Unrelated;

    /** The file declares no type for the receiver. */
    case Unknown;
}
