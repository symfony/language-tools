<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\DebouncedRuntimeRefreshScheduler;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;

final class DebouncedRuntimeRefreshSchedulerTest extends TestCase
{
    public function testCollapsesRapidRefreshesPerProject(): void
    {
        $initializer = new DebouncedRuntimeInitializer();
        $scheduler = new DebouncedRuntimeRefreshScheduler($initializer, 0.001);
        $project = new Project('/workspace', 'file:///workspace', '^8.0');

        $scheduler->schedule($project);
        $scheduler->schedule($project);
        $scheduler->schedule($project);
        EventLoop::run();

        self::assertSame(['/workspace'], $initializer->projects);
    }
}

final class DebouncedRuntimeInitializer implements RuntimeInitializerInterface
{
    /** @var list<string> */
    public array $projects = [];

    public function initialize(Project $project): void
    {
        $this->projects[] = $project->rootPath();
    }
}
