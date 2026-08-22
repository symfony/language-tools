<?php

namespace Symfony\Lsp\Tools\Dogfood;

interface ProvisionerInterface
{
    /**
     * Creates a clean working tree at the pinned revision and returns its root.
     */
    public function provision(ProjectConfiguration $configuration): string;

    public function release(ProjectConfiguration $configuration): void;
}
