<?php

namespace Symfony\Lsp\Tests\Runtime;

use Amp\Cancellation;
use Amp\CancelledException;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Runtime\DebouncedRuntimeRefreshScheduler;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshMode;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;

use function Amp\delay;

final class DebouncedRuntimeRefreshSchedulerTest extends TestCase
{
    private static function projects(Project ...$projects): ProjectRegistry
    {
        $registry = new ProjectRegistry();
        $registry->replace(array_values($projects));

        return $registry;
    }

    public function testCollapsesAndCombinesRapidRefreshesPerProject(): void
    {
        $initializer = new DebouncedRuntimeInitializer();
        $project = new Project('/workspace', 'file:///workspace');
        $scheduler = new DebouncedRuntimeRefreshScheduler($initializer, self::projects($project), 0.001);

        $scheduler->schedule($project, RuntimeRefreshPlan::preserve(['routes']));
        $scheduler->schedule($project, RuntimeRefreshPlan::preserve(['assets']));
        EventLoop::run();

        self::assertSame(['/workspace'], $initializer->projects);
        self::assertSame(['routes', 'assets'], $initializer->plans[0]->sections());
        self::assertSame(RuntimeRefreshMode::Preserve, $initializer->plans[0]->mode());
    }

    public function testSerializesRefreshesAndQueuesOneReplacement(): void
    {
        $initializer = new QueuingRuntimeInitializer();
        $project = new Project('/workspace', 'file:///workspace');
        $scheduler = new DebouncedRuntimeRefreshScheduler($initializer, self::projects($project), 0.001);
        $initializer->scheduler = $scheduler;

        $scheduler->schedule($project, RuntimeRefreshPlan::reuse());
        EventLoop::run();

        self::assertSame([RuntimeRefreshMode::Reuse, RuntimeRefreshMode::Rebuild], array_map(
            static fn (RuntimeRefreshPlan $plan): RuntimeRefreshMode => $plan->mode(),
            $initializer->plans,
        ));
        self::assertTrue($initializer->plans[1]->refreshesEverySection());
        self::assertSame(1, $initializer->maximumActive);
    }

    public function testNewProjectInstanceSupersedesDelayedRefreshForTheSameRoot(): void
    {
        $initializer = new DebouncedRuntimeInitializer();
        $project = new Project('/workspace', 'file:///workspace');
        $registry = self::projects($project);
        $scheduler = new DebouncedRuntimeRefreshScheduler($initializer, $registry, 0.001);

        $scheduler->schedule($project, RuntimeRefreshPlan::preserve(['routes']));
        $replacement = new Project('/workspace', 'file:///workspace');
        $registry->replace([$replacement]);
        $scheduler->schedule($replacement, RuntimeRefreshPlan::rebuild());
        EventLoop::run();

        self::assertSame([$replacement], $initializer->instances);
        self::assertSame(RuntimeRefreshMode::Rebuild, $initializer->plans[0]->mode());
    }

    public function testRemovalCancelsDelayedRefreshes(): void
    {
        $initializer = new DebouncedRuntimeInitializer();
        $project = new Project('/workspace', 'file:///workspace');
        $registry = self::projects($project);
        $scheduler = new DebouncedRuntimeRefreshScheduler($initializer, $registry, 0.001);

        $scheduler->schedule($project, RuntimeRefreshPlan::rebuild());
        $registry->replace([]);
        $scheduler->removeProject($project);
        EventLoop::run();

        self::assertSame([], $initializer->projects);
    }

    public function testRemovalCancelsTheActiveRefreshAndDropsQueuedPlans(): void
    {
        $initializer = new BlockingRuntimeInitializer();
        $project = new Project('/workspace', 'file:///workspace');
        $registry = self::projects($project);
        $scheduler = new DebouncedRuntimeRefreshScheduler($initializer, $registry, 0.001);

        $scheduler->schedule($project, RuntimeRefreshPlan::rebuild());
        EventLoop::queue(static function () use ($scheduler, $registry, $project): void {
            delay(0.005);
            $scheduler->schedule($project, RuntimeRefreshPlan::rebuild());
            $registry->replace([]);
            $scheduler->removeProject($project);
        });
        EventLoop::run();

        self::assertSame(1, $initializer->starts);
        self::assertTrue($initializer->cancelled);
    }

    public function testDoesNotRunForProjectsRemovedFromTheRegistry(): void
    {
        $initializer = new DebouncedRuntimeInitializer();
        $project = new Project('/workspace', 'file:///workspace');
        $registry = self::projects($project);
        $scheduler = new DebouncedRuntimeRefreshScheduler($initializer, $registry, 0.001);

        $scheduler->schedule($project, RuntimeRefreshPlan::rebuild());
        $registry->replace([]);
        EventLoop::run();

        self::assertSame([], $initializer->projects);
    }
}

final class BlockingRuntimeInitializer implements RuntimeInitializerInterface
{
    public int $starts = 0;
    public bool $cancelled = false;

    public function initialize(Project $project, RuntimeRefreshPlan $plan, ?Cancellation $cancellation = null): void
    {
        ++$this->starts;
        try {
            delay(1.0, cancellation: $cancellation);
        } catch (CancelledException $error) {
            $this->cancelled = true;

            throw $error;
        }
    }
}

final class DebouncedRuntimeInitializer implements RuntimeInitializerInterface
{
    /** @var list<string> */
    public array $projects = [];

    /** @var list<Project> */
    public array $instances = [];

    /** @var list<RuntimeRefreshPlan> */
    public array $plans = [];

    public function initialize(Project $project, RuntimeRefreshPlan $plan, ?Cancellation $cancellation = null): void
    {
        $this->projects[] = $project->rootPath;
        $this->instances[] = $project;
        $this->plans[] = $plan;
    }
}

final class QueuingRuntimeInitializer implements RuntimeInitializerInterface
{
    public DebouncedRuntimeRefreshScheduler $scheduler;

    /** @var list<RuntimeRefreshPlan> */
    public array $plans = [];
    public int $maximumActive = 0;
    private int $active = 0;

    public function initialize(Project $project, RuntimeRefreshPlan $plan, ?Cancellation $cancellation = null): void
    {
        $this->plans[] = $plan;
        ++$this->active;
        $this->maximumActive = max($this->maximumActive, $this->active);
        if (1 === \count($this->plans)) {
            $this->scheduler->schedule($project, RuntimeRefreshPlan::preserve(['translations']));
            $this->scheduler->schedule($project, RuntimeRefreshPlan::rebuild());
            delay(0.01);
        }
        --$this->active;
    }
}
