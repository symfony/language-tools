<?php

namespace Symfony\Lsp\Feature\Configuration;

final class ConfigurationValidationResult
{
    public const INVALID = 'invalid';
    public const PENDING = 'pending';
    public const UNAVAILABLE = 'unavailable';
    public const VALID = 'valid';

    public function __construct(
        public readonly string $state,
        public readonly ?string $environment = null,
        public readonly ?string $kind = null,
        public readonly ?string $path = null,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
    ) {
    }
}
