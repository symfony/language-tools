<?php

namespace Symfony\Lsp\Project;

/**
 * The analysis settings one source configures, with null for every option that
 * source leaves to the next one.
 */
final readonly class ProjectAnalysisSettings
{
    /**
     * @param non-empty-list<string>|null $phpCommand
     * @param string|null                 $containerProjectRoot An empty string configures no container root
     * @param list<string>|null           $excludePaths
     */
    public function __construct(
        public ?array $phpCommand = null,
        public ?string $containerProjectRoot = null,
        public ?string $environment = null,
        public ?string $kernel = null,
        public ?bool $debug = null,
        public ?bool $runtimeIndexing = null,
        public ?bool $releaseMetadata = null,
        public ?float $bridgeTimeout = null,
        public ?bool $translationDiagnostics = null,
        public ?array $excludePaths = null,
    ) {
    }

    /** Every option the given settings configure wins over this one. */
    public function merge(self $settings): self
    {
        return new self(
            $settings->phpCommand ?? $this->phpCommand,
            $settings->containerProjectRoot ?? $this->containerProjectRoot,
            $settings->environment ?? $this->environment,
            $settings->kernel ?? $this->kernel,
            $settings->debug ?? $this->debug,
            $settings->runtimeIndexing ?? $this->runtimeIndexing,
            $settings->releaseMetadata ?? $this->releaseMetadata,
            $settings->bridgeTimeout ?? $this->bridgeTimeout,
            $settings->translationDiagnostics ?? $this->translationDiagnostics,
            $settings->excludePaths ?? $this->excludePaths,
        );
    }

    /** Leaves the kernel to the next source, which detects it again. */
    public function withoutKernel(): self
    {
        return new self(
            $this->phpCommand,
            $this->containerProjectRoot,
            $this->environment,
            null,
            $this->debug,
            $this->runtimeIndexing,
            $this->releaseMetadata,
            $this->bridgeTimeout,
            $this->translationDiagnostics,
            $this->excludePaths,
        );
    }
}
