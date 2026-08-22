<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use Symfony\Lsp\Tools\Dogfood\HarnessInterface;
use Symfony\Lsp\Tools\Dogfood\HarnessResult;
use Symfony\Lsp\Tools\Dogfood\ProjectConfiguration;

final class FakeHarness implements HarnessInterface
{
    /** @var list<string> */
    public array $applicationRoots = [];

    /** @var list<HarnessResult> */
    private array $results;

    public function __construct(HarnessResult ...$results)
    {
        $this->results = array_values($results);
    }

    public function run(ProjectConfiguration $configuration, string $applicationRoot): HarnessResult
    {
        $this->applicationRoots[] = $applicationRoot;

        return array_shift($this->results) ?? throw new \LogicException('No harness result queued.');
    }
}
