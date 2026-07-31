<?php

namespace Symfony\Lsp\Tests\Runtime;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\DebouncedRuntimeRefreshScheduler;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshMode;

use function Amp\delay;

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

    public function testSerializesRefreshesAndQueuesOneReplacement(): void
    {
        $initializer = new QueuingRuntimeInitializer();
        $scheduler = new DebouncedRuntimeRefreshScheduler($initializer, 0.001);
        $initializer->scheduler = $scheduler;
        $project = new Project('/workspace', 'file:///workspace', '^8.0');

        $scheduler->schedule($project, RuntimeRefreshMode::Reuse);
        EventLoop::run();

        self::assertSame([RuntimeRefreshMode::Reuse, RuntimeRefreshMode::Clear], $initializer->modes);
        self::assertSame(1, $initializer->maximumActive);
    }
}

final class DebouncedRuntimeInitializer implements RuntimeInitializerInterface
{
    /** @var list<string> */
    public array $projects = [];

    public function initialize(Project $project, RuntimeRefreshMode $mode = RuntimeRefreshMode::Reuse, ?Cancellation $cancellation = null): void
    {
        $this->projects[] = $project->rootPath();
    }
}

final class QueuingRuntimeInitializer implements RuntimeInitializerInterface
{
    public DebouncedRuntimeRefreshScheduler $scheduler;

    /** @var list<RuntimeRefreshMode> */
    public array $modes = [];
    public int $maximumActive = 0;
    private int $active = 0;

    public function initialize(Project $project, RuntimeRefreshMode $mode = RuntimeRefreshMode::Reuse, ?Cancellation $cancellation = null): void
    {
        $this->modes[] = $mode;
        ++$this->active;
        $this->maximumActive = max($this->maximumActive, $this->active);
        if (1 === \count($this->modes)) {
            $this->scheduler->schedule($project, RuntimeRefreshMode::Warmup);
            $this->scheduler->schedule($project, RuntimeRefreshMode::Clear);
            delay(0.01);
        }
        --$this->active;
    }
}
