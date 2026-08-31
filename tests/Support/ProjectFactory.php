<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

final class ProjectFactory
{
    public function __construct(private readonly UriToPathConverter $uriConverter = new UriToPathConverter())
    {
    }

    public function create(string $rootPath = '/workspace', string $frameworkBundleConstraint = '^8.0', ?string $rootUri = null): Project
    {
        return new Project($rootPath, $rootUri ?? $this->uriConverter->toUri($rootPath), $frameworkBundleConstraint);
    }

    public function registry(Project ...$projects): ProjectRegistry
    {
        $registry = new ProjectRegistry();
        $registry->replace([] === $projects ? [$this->create()] : array_values($projects));

        return $registry;
    }
}
