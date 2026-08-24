<?php

namespace Symfony\Lsp\Feature\Configuration;

final class ConfigurationValidationException extends \RuntimeException
{
    public function __construct(public readonly ConfigurationValidationResult $validation)
    {
        parent::__construct('The application configuration is invalid.');
    }
}
