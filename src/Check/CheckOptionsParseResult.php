<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Project\InvalidConfigurationException;

final class CheckOptionsParseResult
{
    public function __construct(
        public readonly string $format,
        public readonly CheckOptions|InvalidConfigurationException $value,
    ) {
    }
}
