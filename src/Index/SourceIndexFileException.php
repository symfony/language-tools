<?php

namespace Symfony\Lsp\Index;

final class SourceIndexFileException extends \RuntimeException
{
    public function __construct(string $relativePath, \Throwable $previous)
    {
        parent::__construct(\sprintf('Unable to index source file "%s".', $relativePath), previous: $previous);
    }
}
