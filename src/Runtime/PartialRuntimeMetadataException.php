<?php

namespace Symfony\Lsp\Runtime;

/** @phpstan-import-type RuntimeMetadataSectionError from RuntimeMetadataException */
final class PartialRuntimeMetadataException extends RuntimeMetadataException
{
    /**
     * @param non-empty-list<string>            $sections
     * @param list<RuntimeMetadataSectionError> $sectionErrors
     */
    public function __construct(array $sections, array $sectionErrors = [])
    {
        parent::__construct($sections, $sectionErrors);
    }
}
