<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ProcessInterruptedException extends \RuntimeException
{
    public function __construct(
        public readonly int $signal,
    ) {
        parent::__construct(\sprintf('Process interrupted by signal %d.', $signal));
    }
}
