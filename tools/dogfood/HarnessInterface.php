<?php

namespace Symfony\Lsp\Tools\Dogfood;

interface HarnessInterface
{
    public function run(ProjectConfiguration $configuration, string $applicationRoot): HarnessResult;
}
