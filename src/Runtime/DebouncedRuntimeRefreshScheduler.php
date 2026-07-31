<?php

namespace Symfony\Lsp\Runtime;

use Revolt\EventLoop;
use Symfony\Lsp\Project\Project;

final class DebouncedRuntimeRefreshScheduler implements RuntimeRefreshSchedulerInterface
{
    /** @var array<string, string> */
    private array $watchers = [];

    /** @var array<string, RuntimeRefreshMode> */
    private array $pendingModes = [];

    /** @var array<string, true> */
    private array $running = [];

    /** @var array<string, RuntimeRefreshMode> */
    private array $queuedModes = [];

    public function __construct(
        private readonly RuntimeInitializerInterface $runtimeInitializer,
        private readonly float $delay = 0.2,
    ) {
        if ($delay < 0) {
            throw new \InvalidArgumentException('The refresh delay cannot be negative.');
        }
    }

    public function schedule(Project $project, RuntimeRefreshMode $mode = RuntimeRefreshMode::Clear): void
    {
        $key = $project->rootPath();
        $this->pendingModes[$key] = isset($this->pendingModes[$key])
            ? $this->pendingModes[$key]->combine($mode)
            : $mode;
        if (isset($this->watchers[$key])) {
            EventLoop::cancel($this->watchers[$key]);
        }

        $this->watchers[$key] = EventLoop::delay($this->delay, function () use ($key, $project): void {
            unset($this->watchers[$key]);
            $mode = $this->pendingModes[$key] ?? RuntimeRefreshMode::Reuse;
            unset($this->pendingModes[$key]);
            $this->run($project, $mode);
        });
    }

    private function run(Project $project, RuntimeRefreshMode $mode): void
    {
        $key = $project->rootPath();
        if (isset($this->running[$key])) {
            $this->queuedModes[$key] = isset($this->queuedModes[$key])
                ? $this->queuedModes[$key]->combine($mode)
                : $mode;

            return;
        }

        $this->running[$key] = true;
        try {
            $this->runtimeInitializer->initialize($project, $mode);
        } finally {
            unset($this->running[$key]);
            $queuedMode = $this->queuedModes[$key] ?? null;
            unset($this->queuedModes[$key]);
            if (null !== $queuedMode) {
                EventLoop::queue(fn () => $this->run($project, $queuedMode));
            }
        }
    }
}
