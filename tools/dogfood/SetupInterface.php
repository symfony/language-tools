<?php

namespace Symfony\Lsp\Tools\Dogfood;

interface SetupInterface
{
    /**
     * Installs the dependency set and prepares the application for indexing.
     *
     * @throws SetupException
     */
    public function setUp(string $applicationRoot): void;
}
