<?php

namespace Symfony\Lsp\Runtime;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Revolt\EventLoop;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\ProjectStateInterface;

final class DebouncedRuntimeRefreshScheduler implements RuntimeRefreshSchedulerInterface, ProjectStateInterface
{
    /** @var array<string, string> */
    private array $watchers = [];

    /** @var array<string, RuntimeRefreshPlan> */
    private array $pendingPlans = [];

    /** @var array<string, true> */
    private array $running = [];

    /** @var array<string, DeferredCancellation> */
    private array $activeRuns = [];

    /** @var array<string, RuntimeRefreshPlan> */
    private array $queuedPlans = [];

    public function __construct(
        private readonly RuntimeInitializerInterface $runtimeInitializer,
        private readonly ProjectRegistry $projects,
        private readonly float $delay = 0.2,
    ) {
        if ($delay < 0) {
            throw new \InvalidArgumentException('The refresh delay cannot be negative.');
        }
    }

    public function schedule(Project $project, ?RuntimeRefreshPlan $plan = null): void
    {
        $plan ??= new RuntimeRefreshPlan(RuntimeRefreshMode::Clear);
        $key = $project->rootPath();
        $this->pendingPlans[$key] = isset($this->pendingPlans[$key])
            ? $this->pendingPlans[$key]->combine($plan)
            : $plan;
        if (isset($this->watchers[$key])) {
            EventLoop::cancel($this->watchers[$key]);
        }

        $this->watchers[$key] = EventLoop::delay($this->delay, function () use ($key, $project): void {
            unset($this->watchers[$key]);
            $plan = $this->pendingPlans[$key] ?? new RuntimeRefreshPlan();
            unset($this->pendingPlans[$key]);
            $this->run($project, $plan);
        });
    }

    public function removeProject(Project $project): void
    {
        $key = $project->rootPath();
        if (isset($this->watchers[$key])) {
            EventLoop::cancel($this->watchers[$key]);
        }
        ($this->activeRuns[$key] ?? null)?->cancel();
        unset($this->watchers[$key], $this->pendingPlans[$key], $this->queuedPlans[$key], $this->activeRuns[$key]);
    }

    private function run(Project $project, RuntimeRefreshPlan $plan): void
    {
        if (!$this->projects->contains($project)) {
            return;
        }
        $key = $project->rootPath();
        if (isset($this->running[$key])) {
            $this->queuedPlans[$key] = isset($this->queuedPlans[$key])
                ? $this->queuedPlans[$key]->combine($plan)
                : $plan;

            return;
        }

        $this->running[$key] = true;
        $cancellation = $this->activeRuns[$key] = new DeferredCancellation();
        try {
            $this->runtimeInitializer->initialize($project, $plan, $cancellation->getCancellation());
        } catch (CancelledException) {
            // the only cancellation source is project removal
        } finally {
            $this->releaseActiveRun($key, $cancellation);
            unset($this->running[$key]);
            $queuedPlan = $this->queuedPlans[$key] ?? null;
            unset($this->queuedPlans[$key]);
            if (null !== $queuedPlan) {
                EventLoop::queue(fn () => $this->run($project, $queuedPlan));
            }
        }
    }

    private function releaseActiveRun(string $key, DeferredCancellation $cancellation): void
    {
        if (($this->activeRuns[$key] ?? null) === $cancellation) {
            unset($this->activeRuns[$key]);
        }
    }
}
