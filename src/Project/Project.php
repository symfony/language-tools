<?php

namespace Symfony\Lsp\Project;

final class Project
{
    /**
     * @param string|null $vendorPath the Composer installation directory relative to the root, or null when it sits outside the project
     * @param string|null $configPath the application configuration directory relative to the root, or null when it sits outside the project
     */
    public function __construct(
        public readonly string $rootPath,
        public readonly string $rootUri,
        public readonly ?string $vendorPath = 'vendor',
        public readonly ?string $configPath = 'config',
    ) {
    }

    public function configRelativePath(string $relativePath): ?string
    {
        if (null === $this->configPath || !str_starts_with($relativePath, $this->configPath.'/')) {
            return null;
        }

        return substr($relativePath, \strlen($this->configPath) + 1);
    }

    public function isRouteConfigPath(string $relativePath): bool
    {
        $configRelativePath = $this->configRelativePath($relativePath);

        return null !== $configRelativePath && (str_starts_with($configRelativePath, 'routes.') || str_starts_with($configRelativePath, 'routes/'));
    }
}
