<?php

namespace Symfony\Lsp\Protocol;

interface MessageWriterInterface
{
    public function write(string $message): void;
}
