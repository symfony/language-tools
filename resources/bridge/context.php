<?php

final class SymfonyLspBridgeContext
{
    private const CONFIGURATION_EXCEPTIONS = [
        'Symfony\\Component\\Config\\Definition\\Exception\\DuplicateKeyException',
        'Symfony\\Component\\Config\\Definition\\Exception\\ForbiddenOverwriteException',
        'Symfony\\Component\\Config\\Definition\\Exception\\InvalidConfigurationException',
        'Symfony\\Component\\Config\\Definition\\Exception\\InvalidTypeException',
    ];

    private ?object $kernel = null;
    private ?object $application = null;
    private ?Throwable $kernelError = null;
    private bool $kernelErrorReported = false;
    private array $errors = [];

    public function __construct(
        private string $project,
        private string $environment,
        private bool $debug,
        private bool $targetedRefresh,
        private bool $rebuildContainer,
        private bool $errorDetails,
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
        if (method_exists($application, 'setCatchExceptions')) {
            $application->setCatchExceptions(false);
        }

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
                $kernel = $this->bootKernel();
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

    public function configurationValidation(): array
    {
        try {
            $this->kernel();

            return ['status' => 'valid'];
        } catch (Throwable $error) {
            return $this->classifyConfigurationValidation($error);
        }
    }

    private function bootKernel(): object
    {
        $kernelClass = $this->conventionalKernelClass();
        if (null === $kernelClass) {
            $kernel = $this->frontControllerKernel();
            if (null === $kernel) {
                throw new RuntimeException('No kernel was found. Expected App\\Kernel, a Kernel class at a Composer PSR-4 autoload root, app/AppKernel.php or a front controller using the Symfony Runtime.');
            }

            return $this->boot($kernel);
        }

        try {
            return $this->boot(new $kernelClass($this->environment, $this->debug));
        } catch (Throwable $error) {
            // distributions such as Pimcore ship a conventional kernel that only boots after their front controller's bootstrap
            $kernel = $this->frontControllerKernel();
            if (null === $kernel) {
                throw $error;
            }

            return $this->boot($kernel);
        }
    }

    private function boot(object $kernel): object
    {
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

        return $kernel;
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

    private function classifyConfigurationValidation(Throwable $error): array
    {
        for ($candidate = $error, $depth = 0; $candidate instanceof Throwable && $depth < 32; $candidate = $candidate->getPrevious(), ++$depth) {
            $class = $candidate::class;
            if (in_array($class, self::CONFIGURATION_EXCEPTIONS, true)) {
                $result = ['status' => 'invalid', 'kind' => 'configuration'];
                $path = $this->normalizedConfigurationPath($candidate);
                if (null !== $path) {
                    $result['path'] = $path;
                }

                return $result;
            }
            if ('Symfony\\Component\\Yaml\\Exception\\ParseException' === $class) {
                return array_replace(
                    ['status' => 'invalid', 'kind' => 'yaml'],
                    $this->projectLocation($candidate),
                );
            }
            if ('Symfony\\Component\\Config\\Util\\Exception\\XmlParsingException' === $class) {
                return ['status' => 'invalid', 'kind' => 'xml'];
            }
        }

        return ['status' => 'unavailable'];
    }

    private function normalizedConfigurationPath(Throwable $error): ?string
    {
        if (!method_exists($error, 'getPath')) {
            return null;
        }
        try {
            $path = $error->getPath();
        } catch (Throwable) {
            return null;
        }
        if (!is_string($path)) {
            return null;
        }
        $path = trim($path);
        $path = trim($path, '.');
        $path = trim($path);
        if ('' === $path || 512 < strlen($path)) {
            return null;
        }
        $segments = preg_split('/\\.+/', $path, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($segments) || [] === $segments) {
            return null;
        }
        foreach ($segments as $segment) {
            if (1 !== preg_match('/\\A[A-Za-z0-9_:\\\\-]+\\z/D', $segment)) {
                return null;
            }
        }

        return implode('.', $segments);
    }

    private function projectLocation(Throwable $error): array
    {
        $location = [];
        try {
            $file = $error->getParsedFile();
            if (is_string($file)) {
                $file = $this->projectRelativeFile($file);
                if (null !== $file) {
                    $location['file'] = $file;
                }
            }
        } catch (Throwable) {
        }
        try {
            $line = $error->getParsedLine();
            if (is_int($line) && 0 < $line) {
                $location['line'] = $line;
            }
        } catch (Throwable) {
        }

        return $location;
    }

    private function projectRelativeFile(string $file): ?string
    {
        $root = realpath($this->project);
        if (false === $root) {
            return null;
        }
        if (!$this->isAbsolutePath($file)) {
            $file = rtrim($this->project, '/\\').'/'.$file;
        }
        $file = realpath($file);
        if (false === $file) {
            return null;
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $file = str_replace('\\', '/', $file);
        $prefix = $root.'/';
        $inside = '\\' === DIRECTORY_SEPARATOR
            ? 0 === strncasecmp($file, $prefix, strlen($prefix))
            : str_starts_with($file, $prefix);
        if (!$inside) {
            return null;
        }
        $relative = substr($file, strlen($prefix));
        if ([] !== array_intersect(explode('/', $relative), symfonyLspBridgeExcludedDirectories())) {
            return null;
        }

        return $relative;
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

    public function addError(string $section, ?Throwable $error = null): void
    {
        if ($error instanceof Throwable && $error === $this->kernelError) {
            if (!$this->kernelErrorReported) {
                $this->kernelErrorReported = true;
                $this->errors[] = $this->errorEntry('runtime', 'The application kernel could not be booted.', $error);
            }
            $error = null;
        }
        $this->errors[] = $this->errorEntry($section, sprintf('Unable to load the "%s" runtime metadata section.', $section), $error);
    }

    private function errorEntry(string $section, string $message, ?Throwable $error): array
    {
        $entry = ['section' => $section, 'message' => $message];
        if ($this->errorDetails && $error instanceof Throwable) {
            $entry['cause'] = $this->errorCause($error);
        }

        return $entry;
    }

    private function errorCause(Throwable $error): array
    {
        $chain = [];
        for ($candidate = $error, $depth = 0; $candidate instanceof Throwable && $depth < 3; $candidate = $candidate->getPrevious(), ++$depth) {
            $cause = [
                'class' => $this->errorText($candidate::class, 300, false),
                'message' => $this->errorText($candidate->getMessage(), 300),
                'frames' => [],
            ];
            $origin = $this->errorOrigin($candidate->getFile(), $candidate->getLine());
            if (null !== $origin) {
                $cause['origin'] = $origin;
            }
            foreach (array_slice($candidate->getTrace(), 0, 5) as $frame) {
                if (!is_array($frame)) {
                    continue;
                }
                $call = '';
                if (is_string($frame['class'] ?? null)) {
                    $call .= $this->errorText($frame['class'], 300, false);
                }
                if (is_string($frame['type'] ?? null) && in_array($frame['type'], ['->', '::'], true)) {
                    $call .= $frame['type'];
                }
                if (is_string($frame['function'] ?? null)) {
                    $call .= $this->errorText($frame['function'], 300, false);
                }
                $frameOrigin = is_string($frame['file'] ?? null) && is_int($frame['line'] ?? null)
                    ? $this->errorOrigin($frame['file'], $frame['line'])
                    : null;
                if ('' === $call && null === $frameOrigin) {
                    continue;
                }
                $cause['frames'][] = '' === $call
                    ? $frameOrigin
                    : $call.(null === $frameOrigin ? '' : ' ('.$frameOrigin.')');
            }
            $chain[] = $cause;
        }

        return ['chain' => $chain];
    }

    private function errorOrigin(string $file, int $line): ?string
    {
        $file = $this->errorFile($file);
        if (null === $file) {
            return null;
        }

        return $file.(0 < $line ? ':'.$line : '');
    }

    private function errorFile(string $file): ?string
    {
        $file = str_replace('\\', '/', $file);
        $root = realpath($this->project);
        $resolved = realpath($file);
        if (false !== $root && false !== $resolved) {
            $root = rtrim(str_replace('\\', '/', $root), '/');
            $resolved = str_replace('\\', '/', $resolved);
            $prefix = $root.'/';
            $inside = '\\' === DIRECTORY_SEPARATOR
                ? 0 === strncasecmp($resolved, $prefix, strlen($prefix))
                : str_starts_with($resolved, $prefix);
            if ($inside) {
                return $this->errorText(substr($resolved, strlen($prefix)), 500, false);
            }
        }
        $file = basename($file);

        return '' === $file ? null : $this->errorText($file, 500, false);
    }

    private function errorText(string $value, int $limit, bool $redactValues = true): string
    {
        if (function_exists('mb_scrub')) {
            $value = mb_scrub($value, 'UTF-8');
        } elseif (1 !== preg_match('//u', $value)) {
            $value = '[invalid UTF-8]';
        }
        $roots = array_values(array_unique(array_filter([
            $this->project,
            realpath($this->project) ?: null,
        ], 'is_string')));
        foreach ($roots as $root) {
            $root = rtrim(str_replace('\\', '/', $root), '/');
            $value = str_replace([$root, str_replace('/', '\\', $root)], '.', $value);
        }
        if ($redactValues) {
            $sensitiveValues = [];
            foreach (array_merge($_SERVER, $_ENV) as $environmentValue) {
                if (is_string($environmentValue) && strlen($environmentValue) >= 4) {
                    $sensitiveValues[] = $environmentValue;
                }
            }
            $sensitiveValues = array_values(array_unique($sensitiveValues));
            usort($sensitiveValues, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
            foreach ($sensitiveValues as $environmentValue) {
                $value = str_replace($environmentValue, '[redacted]', $value);
            }
            $value = preg_replace('/\b[A-Z][A-Z0-9_]{2,}\s*=\s*[^\s,;]+/', '[redacted]', $value) ?? '[redacted]';
            $value = preg_replace('/\b[a-z][a-z0-9+.-]*:\/\/[^\s\/:@]+:[^\s\/@]+@/i', '[redacted]@', $value) ?? '[redacted]';
            $value = preg_replace('/\bauthorization\s*[=:]\s*[^\r\n,;]+/i', 'authorization=[redacted]', $value) ?? '[redacted]';
            $value = preg_replace(
                '/\b(password|passwd|secret|token|credential|cookie|api[_-]?key|private[_-]?key)\s*[=:]\s*[^\s,;]+/i',
                '$1=[redacted]',
                $value,
            ) ?? '[redacted]';
        }
        $value = preg_replace('/[\x00-\x20\x7F]+/', ' ', $value) ?? '';
        $value = trim($value);

        return $this->truncateErrorText($value, $limit);
    }

    private function truncateErrorText(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }
        $value = substr($value, 0, $limit - 3);
        while ('' !== $value && 1 !== preg_match('//u', $value)) {
            $value = substr($value, 0, -1);
        }

        return $value.'...';
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
