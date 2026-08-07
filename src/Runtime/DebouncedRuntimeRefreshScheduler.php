<?php

namespace Symfony\Lsp\Runtime;

use Revolt\EventLoop;
use Symfony\Lsp\Project\Project;

final class DebouncedRuntimeRefreshScheduler implements RuntimeRefreshSchedulerInterface
{
    /** @var array<string, string> */
    private array $watchers = [];

    /** @var array<string, RuntimeRefreshPlan> */
    private array $pendingPlans = [];

    /** @var array<string, true> */
    private array $running = [];

    /** @var array<string, RuntimeRefreshPlan> */
    private array $queuedPlans = [];

    public function __construct(
        private readonly RuntimeInitializerInterface $runtimeInitializer,
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

    private function run(Project $project, RuntimeRefreshPlan $plan): void
    {
        $key = $project->rootPath();
        if (isset($this->running[$key])) {
            $this->queuedPlans[$key] = isset($this->queuedPlans[$key])
                ? $this->queuedPlans[$key]->combine($plan)
                : $plan;

            return;
        }

        $this->running[$key] = true;
        try {
            $this->runtimeInitializer->initialize($project, $plan);
        } finally {
            unset($this->running[$key]);
            $queuedPlan = $this->queuedPlans[$key] ?? null;
            unset($this->queuedPlans[$key]);
            if (null !== $queuedPlan) {
                EventLoop::queue(fn () => $this->run($project, $queuedPlan));
            }
        }
    }
}
