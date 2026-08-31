<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ProjectReport
{
    public ?ProjectFailure $failure = null;

    /** @var array{modified: list<string>, untracked: int}|null */
    public ?array $workingTree = null;

    public ?string $composerLockSha256 = null;
    public ?string $frameworkBundle = null;
    public ?RunSummary $cold = null;
    public ?RunSummary $warm = null;

    /** @var array<string, float> */
    public array $timings = [];

    public function __construct(
        public readonly ProjectConfiguration $configuration,
    ) {
    }

    public function ok(): bool
    {
        return null === $this->failure
            && null !== $this->cold && [] === $this->cold->layers
            && null !== $this->warm && [] === $this->warm->layers;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->configuration->name,
            'repository' => $this->configuration->repository,
            'revision' => $this->configuration->revision,
            'directory' => $this->configuration->directory,
            'environment' => $this->configuration->environment,
            'setup' => $this->configuration->setup,
            'ci' => $this->configuration->ci,
            'ok' => $this->ok(),
            'failure' => null === $this->failure ? null : ['layer' => $this->failure->layer, 'message' => $this->failure->message],
            'workingTree' => $this->workingTree,
            'dependencies' => ['composerLockSha256' => $this->composerLockSha256],
            'frameworkBundle' => $this->frameworkBundle,
            'timings' => $this->timings,
            'cold' => $this->cold?->toArray(),
            'warm' => $this->warm?->toArray(),
        ];
    }
}
