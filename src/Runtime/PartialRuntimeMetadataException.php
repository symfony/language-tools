<?php

namespace Symfony\Lsp\Runtime;

final class PartialRuntimeMetadataException extends \RuntimeException
{
    /** @param non-empty-list<string> $sections */
    public function __construct(public readonly array $sections)
    {
        parent::__construct('The project bridge could not load runtime metadata: '.implode(', ', $sections).'.');
    }
}
