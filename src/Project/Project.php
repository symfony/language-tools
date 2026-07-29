<?php

namespace Symfony\Lsp\Project;

final class Project
{
    public function __construct(
        private readonly string $rootPath,
        private readonly string $rootUri,
        private readonly string $frameworkBundleConstraint,
    ) {
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    public function rootUri(): string
    {
        return $this->rootUri;
    }

    public function frameworkBundleConstraint(): string
    {
        return $this->frameworkBundleConstraint;
    }
}
