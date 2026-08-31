<?php

namespace Symfony\Lsp\Runtime;

final class UnsupportedSymfonyVersionException extends \RuntimeException
{
    public function __construct(public readonly string $symfonyBranch)
    {
        parent::__construct(\sprintf('Symfony %s is not supported by Symfony Language Tools.', $symfonyBranch));
    }
}
