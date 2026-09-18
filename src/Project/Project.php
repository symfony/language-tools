<?php

namespace Symfony\Lsp\Project;

final class Project
{
    /** @param string|null $vendorPath the Composer installation directory relative to the root, or null when it sits outside the project */
    public function __construct(
        public readonly string $rootPath,
        public readonly string $rootUri,
        public readonly ?string $vendorPath = 'vendor',
    ) {
    }
}
