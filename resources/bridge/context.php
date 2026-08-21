<?php

final class SymfonyLspBridgeContext
{
    private ?object $kernel = null;
    private ?object $application = null;
    private ?Throwable $kernelError = null;
    private array $errors = [];

    public function __construct(
        private string $project,
        private string $environment,
        private bool $debug,
        private bool $targetedRefresh,
        private bool $rebuildContainer,
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

    public function targetedRefresh(): bool
    {
        return $this->targetedRefresh;
    }

    /** @return array{--env: string, --no-debug: bool, --no-interaction: true} */
    public function commandOptions(): array
    {
        return [
            '--env' => $this->environment,
            '--no-debug' => !$this->debug,
            '--no-interaction' => true,
        ];
    }

    public function application(): object
    {
        if (is_object($this->application)) {
            return $this->application;
        }

        $application = new Symfony\Bundle\FrameworkBundle\Console\Application($this->kernel());
        $application->setAutoExit(false);

        return $this->application = $application;
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
            $kernelClass = $this->resolveKernelClass();
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
                if ($this->rebuildContainer) {
                    $directories = [];
                    foreach (['getCacheDir', 'getBuildDir'] as $method) {
                        if (method_exists($kernel, $method) && is_string($directory = $kernel->$method())) {
                            $directories[] = $directory;
                        }
                    }
                    foreach (array_unique($directories) as $directory) {
                        $this->removeDirectory($directory);
                    }
                }
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

    private function resolveKernelClass(): string
    {
        if (class_exists('App\\Kernel')) {
            return 'App\\Kernel';
        }

        foreach ($this->autoloadedKernelCandidates() as $candidate) {
            if (!class_exists($candidate)) {
                continue;
            }
            if (interface_exists(Symfony\Component\HttpKernel\KernelInterface::class)
                && !is_subclass_of($candidate, Symfony\Component\HttpKernel\KernelInterface::class)
            ) {
                continue;
            }

            return $candidate;
        }

        throw new RuntimeException('No kernel class was found. Expected App\\Kernel or a Kernel class at a Composer PSR-4 autoload root.');
    }

    /** @return string[] */
    private function autoloadedKernelCandidates(): array
    {
        $root = rtrim($this->project, '/\\');
        $composerFile = $root.'/composer.json';
        if (!is_file($composerFile)) {
            return [];
        }

        try {
            $composer = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        $psr4 = is_array($composer) ? ($composer['autoload']['psr-4'] ?? null) : null;
        if (!is_array($psr4)) {
            return [];
        }

        $candidates = [];
        foreach ($psr4 as $prefix => $paths) {
            if (!is_string($prefix) || !str_ends_with($prefix, '\\')) {
                continue;
            }
            foreach (is_array($paths) ? $paths : [$paths] as $path) {
                if (is_string($path) && is_file($root.'/'.trim($path, '/').'/Kernel.php')) {
                    $candidates[] = $prefix.'Kernel';
                    break;
                }
            }
        }

        return $candidates;
    }

    private function removeDirectory(string $directory): void
    {
        if (is_link($directory) || is_file($directory)) {
            @unlink($directory);

            return;
        }
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $this->removeDirectory($directory.DIRECTORY_SEPARATOR.$entry);
            }
        }
        @rmdir($directory);
    }

    public function addError(string $section): void
    {
        $this->errors[] = [
            'section' => $section,
            'message' => sprintf('Unable to load the "%s" runtime metadata section.', $section),
        ];
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
