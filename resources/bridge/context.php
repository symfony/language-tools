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
                $kernel = $this->createKernel();
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

    private function createKernel(): object
    {
        $kernelClass = $this->conventionalKernelClass();
        if (null !== $kernelClass) {
            return new $kernelClass($this->environment, $this->debug);
        }

        $kernel = $this->frontControllerKernel();
        if (null !== $kernel) {
            return $kernel;
        }

        throw new RuntimeException('No kernel was found. Expected App\\Kernel, a Kernel class at a Composer PSR-4 autoload root, app/AppKernel.php or a front controller using the Symfony Runtime.');
    }

    private function conventionalKernelClass(): ?string
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

        $legacyKernelFile = rtrim($this->project, '/\\').'/app/AppKernel.php';
        if (!class_exists('AppKernel') && is_file($legacyKernelFile)) {
            require_once $legacyKernelFile;
        }
        if (class_exists('AppKernel')
            && (!interface_exists(Symfony\Component\HttpKernel\KernelInterface::class)
                || is_subclass_of('AppKernel', Symfony\Component\HttpKernel\KernelInterface::class))
        ) {
            return 'AppKernel';
        }

        return null;
    }

    /*
     * Applications with a nonstandard kernel, such as Shopware, declare their
     * bootstrap in their front controllers. Scripts following the Symfony
     * Runtime convention can be included safely: with the autoloader already
     * loaded, autoload_runtime.php is inert and the script returns its app
     * closure instead of running it, so the application's own runtime can
     * resolve the kernel exactly as the application would.
     */
    private function frontControllerKernel(): ?object
    {
        foreach (['bin/console', 'public/index.php'] as $frontController) {
            $path = rtrim($this->project, '/\\').'/'.$frontController;
            if (!is_file($path)) {
                continue;
            }
            $contents = @file_get_contents($path);
            if (false === $contents || !str_contains($contents, 'autoload_runtime.php')) {
                continue;
            }
            $argv = $_SERVER['argv'] ?? null;
            // front controllers parse argv, which must not expose the bridge options
            $_SERVER['argv'] = [$path];
            try {
                $app = require $path;
                if (!$app instanceof Closure) {
                    continue;
                }
                $kernel = $this->resolveRuntimeApplication($app);
            } finally {
                if (null === $argv) {
                    unset($_SERVER['argv']);
                } else {
                    $_SERVER['argv'] = $argv;
                }
            }
            if (null !== $kernel) {
                return $kernel;
            }
        }

        return null;
    }

    private function resolveRuntimeApplication(Closure $app): ?object
    {
        [$configuredRuntimeClass, $configuredOptions] = $this->runtimeConfiguration();
        $runtimeClass = $_SERVER['APP_RUNTIME'] ?? $_ENV['APP_RUNTIME'] ?? $configuredRuntimeClass;
        if (!is_string($runtimeClass) || !class_exists($runtimeClass)) {
            return null;
        }
        $environmentOptions = $_SERVER['APP_RUNTIME_OPTIONS'] ?? $_ENV['APP_RUNTIME_OPTIONS'] ?? [];
        if (is_string($environmentOptions)) {
            $environmentOptions = json_decode($environmentOptions, true, 512, JSON_THROW_ON_ERROR);
        }
        if (!is_array($environmentOptions)) {
            return null;
        }
        $runtime = new $runtimeClass(array_replace($configuredOptions, $environmentOptions, [
            'env' => $this->environment,
            'debug' => $this->debug,
            // the bridge already booted the environment at startup
            'disable_dotenv' => true,
        ]));
        if (!method_exists($runtime, 'getResolver')) {
            return null;
        }
        [$callable, $arguments] = $runtime->getResolver($app)->resolve();

        return $this->extractKernel($callable(...$arguments));
    }

    /** @return array{string, array<string, mixed>} */
    private function runtimeConfiguration(): array
    {
        $runtimeClass = 'Symfony\\Component\\Runtime\\SymfonyRuntime';
        $options = ['project_dir' => $this->project];
        $composerFile = rtrim($this->project, '/\\').'/composer.json';
        if (!is_file($composerFile)) {
            return [$runtimeClass, $options];
        }
        try {
            $composer = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [$runtimeClass, $options];
        }
        $runtime = is_array($composer) ? ($composer['extra']['runtime'] ?? null) : null;
        if (!is_array($runtime)) {
            return [$runtimeClass, $options];
        }
        if (is_string($runtime['class'] ?? null)) {
            $runtimeClass = $runtime['class'];
        }
        $projectDir = $runtime['project_dir'] ?? null;
        unset($runtime['class'], $runtime['autoload_template'], $runtime['project_dir']);
        if (is_string($projectDir) && '' !== $projectDir) {
            if (!$this->isAbsolutePath($projectDir)) {
                $projectDir = rtrim($this->project, '/\\').'/'.trim($projectDir, '/\\');
            }
            $options['project_dir'] = realpath($projectDir) ?: $projectDir;
        }

        return [$runtimeClass, array_replace($runtime, $options)];
    }

    private function isAbsolutePath(string $path): bool
    {
        return 1 === preg_match('{^(?:[/\\\\]|[A-Za-z]:[/\\\\]|[A-Za-z][A-Za-z0-9+.-]*://)}', $path);
    }

    private function extractKernel(mixed $application): ?object
    {
        if (!is_object($application)) {
            return null;
        }
        if (interface_exists(Symfony\Component\HttpKernel\KernelInterface::class)
            && $application instanceof Symfony\Component\HttpKernel\KernelInterface
        ) {
            return $application;
        }
        if (method_exists($application, 'getKernel')) {
            return $this->extractKernel($application->getKernel());
        }

        return null;
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
