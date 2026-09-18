<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;

/**
 * Decides whether a configuration document, or one of its sections, is loaded
 * by the environment the runtime metadata describes.
 */
final class EnvironmentScopeResolver
{
    public function __construct(
        private readonly ProjectPathResolver $projectPaths,
        private readonly RuntimeConfiguration $runtimeConfiguration,
    ) {
    }

    public function includesDocument(Project $project, string $uri): bool
    {
        $relativePath = $this->projectPaths->relative($project, $uri);
        if (null === $relativePath) {
            return true;
        }
        $environment = $this->runtimeConfiguration->environment($project);
        if (preg_match('#^config/(?:packages|routes)/([^/]+)/#D', $relativePath, $matches)) {
            return $environment === $matches[1];
        }
        if (preg_match('#^config/services_([^/]+)\.(?:php|ya?ml)$#iD', $relativePath, $matches)) {
            return $environment === $matches[1];
        }

        return true;
    }

    public function includesSection(Project $project, ?string $environment): bool
    {
        return null === $environment || $environment === $this->runtimeConfiguration->environment($project);
    }
}
