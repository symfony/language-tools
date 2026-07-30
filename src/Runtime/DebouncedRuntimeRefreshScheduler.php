<?php

namespace Symfony\Lsp\Runtime;

use Revolt\EventLoop;
use Symfony\Lsp\Project\Project;

final class DebouncedRuntimeRefreshScheduler implements RuntimeRefreshSchedulerInterface
{
    /** @var array<string, string> */
    private array $watchers = [];

    public function __construct(
        private readonly RuntimeInitializerInterface $runtimeInitializer,
        private readonly float $delay = 0.2,
    ) {
        if ($delay < 0) {
            throw new \InvalidArgumentException('The refresh delay cannot be negative.');
        }
    }

    public function schedule(Project $project): void
    {
        $key = $project->rootPath();
        if (isset($this->watchers[$key])) {
            EventLoop::cancel($this->watchers[$key]);
        }

        $this->watchers[$key] = EventLoop::delay($this->delay, function () use ($key, $project): void {
            unset($this->watchers[$key]);
            $this->runtimeInitializer->initialize($project);
        });
    }
}
