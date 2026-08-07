<?php

namespace Symfony\Lsp\Tests\Runtime;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\DebouncedRuntimeRefreshScheduler;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshMode;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;

use function Amp\delay;

final class DebouncedRuntimeRefreshSchedulerTest extends TestCase
{
    public function testCollapsesAndCombinesRapidRefreshesPerProject(): void
    {
        $initializer = new DebouncedRuntimeInitializer();
        $scheduler = new DebouncedRuntimeRefreshScheduler($initializer, 0.001);
        $project = new Project('/workspace', 'file:///workspace', '^8.0');

        $scheduler->schedule($project, new RuntimeRefreshPlan(RuntimeRefreshMode::Reuse, ['routes'], true));
        $scheduler->schedule($project, new RuntimeRefreshPlan(RuntimeRefreshMode::Reuse, ['assets'], true));
        EventLoop::run();

        self::assertSame(['/workspace'], $initializer->projects);
        self::assertSame(['routes', 'assets'], $initializer->plans[0]->sections());
        self::assertTrue($initializer->plans[0]->preservesContainer());
    }

    public function testSerializesRefreshesAndQueuesOneReplacement(): void
    {
        $initializer = new QueuingRuntimeInitializer();
        $scheduler = new DebouncedRuntimeRefreshScheduler($initializer, 0.001);
        $initializer->scheduler = $scheduler;
        $project = new Project('/workspace', 'file:///workspace', '^8.0');

        $scheduler->schedule($project, new RuntimeRefreshPlan());
        EventLoop::run();

        self::assertSame([RuntimeRefreshMode::Reuse, RuntimeRefreshMode::Clear], array_map(
            static fn (RuntimeRefreshPlan $plan): RuntimeRefreshMode => $plan->mode(),
            $initializer->plans,
        ));
        self::assertNull($initializer->plans[1]->sections());
        self::assertSame(1, $initializer->maximumActive);
    }
}

final class DebouncedRuntimeInitializer implements RuntimeInitializerInterface
{
    /** @var list<string> */
    public array $projects = [];

    /** @var list<RuntimeRefreshPlan> */
    public array $plans = [];

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        $this->projects[] = $project->rootPath();
        $this->plans[] = $plan ?? new RuntimeRefreshPlan();
    }
}

final class QueuingRuntimeInitializer implements RuntimeInitializerInterface
{
    public DebouncedRuntimeRefreshScheduler $scheduler;

    /** @var list<RuntimeRefreshPlan> */
    public array $plans = [];
    public int $maximumActive = 0;
    private int $active = 0;

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        $this->plans[] = $plan ?? new RuntimeRefreshPlan();
        ++$this->active;
        $this->maximumActive = max($this->maximumActive, $this->active);
        if (1 === \count($this->plans)) {
            $this->scheduler->schedule($project, new RuntimeRefreshPlan(RuntimeRefreshMode::Reuse, ['translations'], true));
            $this->scheduler->schedule($project, new RuntimeRefreshPlan(RuntimeRefreshMode::Clear));
            delay(0.01);
        }
        --$this->active;
    }
}
