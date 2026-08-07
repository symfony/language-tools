<?php

final class SymfonyLspBridgeContext
{
    private ?object $kernel = null;
    private ?Throwable $kernelError = null;
    private array $errors = [];

    public function __construct(
        private string $project,
        private string $environment,
        private bool $debug,
        private bool $targetedRefresh,
    ) {
    }

    public function project(): string
    {
        return $this->project;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function debug(): bool
    {
        return $this->debug;
    }

    public function kernel(): object
    {
        if ($this->kernelError instanceof Throwable) {
            throw $this->kernelError;
        }
        if (is_object($this->kernel)) {
            return $this->kernel;
        }

        try {
            $kernelClass = 'App\\Kernel';
            if (!class_exists($kernelClass)) {
                throw new RuntimeException('The default App\\Kernel class was not found.');
            }
            $tracking = $_SERVER['SYMFONY_DISABLE_RESOURCE_TRACKING'] ?? null;
            if ($this->targetedRefresh) {
                $skipAll = filter_var($tracking, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if (true !== $skipAll) {
                    $skipped = null === $skipAll ? explode(',', (string) $tracking) : [];
                    $skipped[] = Symfony\Component\Config\Resource\DirectoryResource::class;
                    $skipped[] = Symfony\Component\Config\Resource\FileResource::class;
                    $skipped[] = Symfony\Component\Config\Resource\ReflectionClassResource::class;
                    $_SERVER['SYMFONY_DISABLE_RESOURCE_TRACKING'] = implode(',', array_unique($skipped));
                }
            }
            try {
                $kernel = new $kernelClass($this->environment, $this->debug);
                if (method_exists($kernel, 'boot')) {
                    $kernel->boot();
                }
            } finally {
                if (null === $tracking) {
                    unset($_SERVER['SYMFONY_DISABLE_RESOURCE_TRACKING']);
                } else {
                    $_SERVER['SYMFONY_DISABLE_RESOURCE_TRACKING'] = $tracking;
                }
            }
            $this->kernel = $kernel;

            return $kernel;
        } catch (Throwable $error) {
            $this->kernelError = $error;
            throw $error;
        }
    }

    public function addError(string $section, string $message): void
    {
        $this->errors[] = ['section' => $section, 'message' => $message];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function shutdown(): void
    {
        if (is_object($this->kernel) && method_exists($this->kernel, 'shutdown')) {
            $this->kernel->shutdown();
        }
    }
}
