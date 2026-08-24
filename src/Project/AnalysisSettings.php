<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;

final class AnalysisSettings
{
    public const PROJECT_KEYS = [
        'phpCommand',
        'containerProjectRoot',
        'environment',
        'debug',
        'runtimeIndexing',
        'bridgeTimeout',
        'translationDiagnostics',
        'excludePaths',
    ];

    /**
     * @param array<array-key, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public function normalizeProject(array $settings, bool $strict = true, string $context = 'settings'): array
    {
        $normalized = [];
        foreach ($settings as $name => $value) {
            if (!\is_string($name) || !\in_array($name, self::PROJECT_KEYS, true)) {
                if ($strict) {
                    throw new InvalidConfigurationException(\sprintf('Unknown %s option "%s".', $context, \is_string($name) ? $name : (string) $name));
                }

                continue;
            }

            try {
                $normalized[$name] = match ($name) {
                    'phpCommand' => $this->phpCommand($value, $context),
                    'containerProjectRoot' => $this->containerProjectRoot($value, $context),
                    'environment' => $this->environment($value, $context),
                    'debug', 'runtimeIndexing', 'translationDiagnostics' => $this->boolean($name, $value, $context),
                    'bridgeTimeout' => $this->positiveNumber($name, $value, $context),
                    'excludePaths' => $this->excludePaths($value, $context),
                };
            } catch (InvalidConfigurationException $error) {
                if ($strict) {
                    throw $error;
                }
            }
        }

        return $normalized;
    }

    /**
     * @return non-empty-list<string>
     */
    private function phpCommand(mixed $value, string $context): array
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

    private function containerProjectRoot(mixed $value, string $context): ?string
    {
        if (null === $value || '' === $value) {
            return null;
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
