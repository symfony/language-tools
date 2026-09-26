<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;

final class AnalysisSettings
{
    public const PROJECT_KEYS = [
        'phpCommand',
        'containerProjectRoot',
        'environment',
        'kernel',
        'debug',
        'runtimeIndexing',
        'releaseMetadata',
        'bridgeTimeout',
        'translationDiagnostics',
        'excludePaths',
    ];

    /** @param array<array-key, mixed> $settings */
    public function normalizeProject(array $settings, bool $strict = true, string $context = 'settings'): ProjectAnalysisSettings
    {
        $phpCommand = null;
        $containerProjectRoot = null;
        $environment = null;
        $kernel = null;
        $debug = null;
        $runtimeIndexing = null;
        $releaseMetadata = null;
        $bridgeTimeout = null;
        $translationDiagnostics = null;
        $excludePaths = null;

        foreach ($settings as $name => $value) {
            if (!\is_string($name) || !\in_array($name, self::PROJECT_KEYS, true)) {
                if ($strict) {
                    throw new InvalidConfigurationException(\sprintf('Unknown %s option "%s".', $context, \is_string($name) ? $name : (string) $name));
                }

                continue;
            }

            try {
                match ($name) {
                    'phpCommand' => $phpCommand = $this->phpCommand($value, $context),
                    'containerProjectRoot' => $containerProjectRoot = $this->containerProjectRoot($value, $context),
                    'environment' => $environment = $this->environment($value, $context),
                    'kernel' => $kernel = $this->kernel($value, $context),
                    'debug' => $debug = $this->boolean($name, $value, $context),
                    'runtimeIndexing' => $runtimeIndexing = $this->boolean($name, $value, $context),
                    'releaseMetadata' => $releaseMetadata = $this->boolean($name, $value, $context),
                    'translationDiagnostics' => $translationDiagnostics = $this->boolean($name, $value, $context),
                    'bridgeTimeout' => $bridgeTimeout = $this->positiveNumber($name, $value, $context),
                    'excludePaths' => $excludePaths = $this->excludePaths($value, $context),
                };
            } catch (InvalidConfigurationException $error) {
                if ($strict) {
                    throw $error;
                }
            }
        }

        return new ProjectAnalysisSettings(
            $phpCommand,
            $containerProjectRoot,
            $environment,
            $kernel,
            $debug,
            $runtimeIndexing,
            $releaseMetadata,
            $bridgeTimeout,
            $translationDiagnostics,
            $excludePaths,
        );
    }

    /**
     * The PHP command is also configured on its own, as the command line option
     * and as the default the Symfony CLI provides.
     *
     * @return non-empty-list<string>
     */
    public function phpCommand(mixed $value, string $context = 'settings'): array
    {
        if (!\is_array($value) || [] === $value || !array_is_list($value)) {
            throw new InvalidConfigurationException(\sprintf('The %s option "phpCommand" must be a non-empty list of strings.', $context));
        }
        foreach ($value as $argument) {
            if (!\is_string($argument) || '' === $argument) {
                throw new InvalidConfigurationException(\sprintf('The %s option "phpCommand" must be a non-empty list of strings.', $context));
            }
        }

        return $value;
    }

    /** An empty value configures no container root, which clears the one another source configured. */
    private function containerProjectRoot(mixed $value, string $context): string
    {
        if (null === $value || '' === $value) {
            return '';
        }
        if (!\is_string($value) || !Path::isAbsolute($value)) {
            throw new InvalidConfigurationException(\sprintf('The %s option "containerProjectRoot" must be an absolute path or null.', $context));
        }

        return Path::canonicalize($value);
    }

    private function environment(mixed $value, string $context): string
    {
        if (!\is_string($value) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $value)) {
            throw new InvalidConfigurationException(\sprintf('The %s option "environment" must contain only letters, numbers, dots, underscores and hyphens.', $context));
        }

        return $value;
    }

    private function kernel(mixed $value, string $context): string
    {
        if (!\is_string($value) || '' === $value || str_contains($value, "\0")) {
            throw new InvalidConfigurationException(\sprintf('The %s option "kernel" must be a kernel class name or a project-relative entry point path.', $context));
        }

        if (str_contains($value, '/') || str_ends_with($value, '.php')) {
            while (str_starts_with($value, './')) {
                $value = substr($value, 2);
            }
            // Windows resolves backslash separators and drive letters, so both are checked on every platform
            if ('' === $value
                || Path::isAbsolute($value)
                || 1 === preg_match('{^[\\\\]|^[A-Za-z]:}', $value)
                || \in_array('..', preg_split('{[/\\\\]}', $value) ?: [], true)
            ) {
                throw new InvalidConfigurationException(\sprintf('The %s option "kernel" must point to an entry point inside each Symfony project.', $context));
            }

            return $value;
        }

        if (!preg_match('/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D', $value)) {
            throw new InvalidConfigurationException(\sprintf('The %s option "kernel" must be a kernel class name or a project-relative entry point path.', $context));
        }

        return ltrim($value, '\\');
    }

    private function boolean(string $name, mixed $value, string $context): bool
    {
        if (!\is_bool($value)) {
            throw new InvalidConfigurationException(\sprintf('The %s option "%s" must be a boolean.', $context, $name));
        }

        return $value;
    }

    /** @return list<string> */
    private function excludePaths(mixed $value, string $context): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new InvalidConfigurationException(\sprintf('The %s option "excludePaths" must be a list of relative path patterns.', $context));
        }

        $patterns = [];
        foreach ($value as $pattern) {
            if (!\is_string($pattern) || '' === $pattern) {
                throw new InvalidConfigurationException(\sprintf('The %s option "excludePaths" must contain non-empty relative path patterns.', $context));
            }
            $pattern = str_replace('\\', '/', $pattern);
            while (str_starts_with($pattern, './')) {
                $pattern = substr($pattern, 2);
            }
            if ('' === $pattern || Path::isAbsolute($pattern) || \in_array('..', explode('/', $pattern), true)) {
                throw new InvalidConfigurationException(\sprintf('The %s option "excludePaths" must contain paths inside each Symfony project.', $context));
            }
            if (str_ends_with($pattern, '/')) {
                $pattern .= '**';
            }
            $patterns[] = $pattern;
        }

        return array_values(array_unique($patterns));
    }

    private function positiveNumber(string $name, mixed $value, string $context): float
    {
        if ((!\is_int($value) && !\is_float($value)) || $value <= 0 || !is_finite((float) $value)) {
            throw new InvalidConfigurationException(\sprintf('The %s option "%s" must be a positive number.', $context, $name));
        }

        return (float) $value;
    }
}
