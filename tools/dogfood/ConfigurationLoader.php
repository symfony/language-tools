<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ConfigurationLoader
{
    private const VERSION = 1;
    private const KEYS = ['version', 'repository', 'revision', 'directory', 'environment', 'setup', 'ci', 'indexTimeout'];
    private const DEFAULT_INDEX_TIMEOUT = 120;
    private const MAX_INDEX_TIMEOUT = 900;

    /**
     * @param list<string> $directories
     * @param list<string> $setupIds
     *
     * @return list<ProjectConfiguration>
     */
    public function load(array $directories, array $setupIds): array
    {
        $configurations = [];
        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                throw new ConfigurationException(\sprintf('Configuration directory "%s" does not exist.', $directory));
            }
            $files = glob($directory.'/*.json');
            foreach (false === $files ? [] : $files as $file) {
                $configuration = $this->loadFile($file, $setupIds);
                if (isset($configurations[$configuration->name])) {
                    throw new ConfigurationException(\sprintf('Duplicate project "%s" in "%s".', $configuration->name, $file));
                }
                $configurations[$configuration->name] = $configuration;
            }
        }
        ksort($configurations);

        return array_values($configurations);
    }

    /**
     * @param list<string> $setupIds
     */
    private function loadFile(string $file, array $setupIds): ProjectConfiguration
    {
        $name = basename($file, '.json');
        if (1 !== preg_match('/^[a-z0-9][a-z0-9._-]*$/', $name)) {
            throw new ConfigurationException(\sprintf('Invalid project name "%s" for "%s".', $name, $file));
        }
        $contents = file_get_contents($file);
        if (false === $contents) {
            throw new ConfigurationException(\sprintf('Unable to read "%s".', $file));
        }
        try {
            $data = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigurationException(\sprintf('Invalid JSON in "%s": %s.', $file, $e->getMessage()));
        }
        if (!\is_array($data)) {
            throw new ConfigurationException(\sprintf('The configuration in "%s" must be an object.', $file));
        }
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, self::KEYS, true)) {
                throw new ConfigurationException(\sprintf('Unknown key "%s" in "%s".', $key, $file));
            }
        }
        if (self::VERSION !== ($data['version'] ?? null)) {
            throw new ConfigurationException(\sprintf('The configuration in "%s" must declare "version": %d.', $file, self::VERSION));
        }

        return new ProjectConfiguration(
            $name,
            $this->repository($data, $file),
            $this->revision($data, $file),
            $this->directory($data, $file),
            $this->environment($data, $file),
            $this->setup($data, $setupIds, $file),
            $this->ci($data, $file),
            $this->indexTimeout($data, $file),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function repository(array $data, string $file): string
    {
        $repository = $data['repository'] ?? null;
        if (!\is_string($repository) || '' === $repository) {
            throw new ConfigurationException(\sprintf('The configuration in "%s" must declare a non-empty "repository" string.', $file));
        }
        if (1 === preg_match('{^(?:/|~|[A-Za-z]:[/\\\\]|file://)}', $repository)) {
            throw new ConfigurationException(\sprintf('The "repository" in "%s" must not be a host path.', $file));
        }
        if (1 === preg_match('{://[^/@]*:[^/@]*@}', $repository)) {
            throw new ConfigurationException(\sprintf('The "repository" in "%s" must not embed credentials.', $file));
        }

        return $repository;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function revision(array $data, string $file): string
    {
        $revision = $data['revision'] ?? null;
        if (!\is_string($revision) || 1 !== preg_match('/^[0-9a-f]{40}$/', $revision)) {
            throw new ConfigurationException(\sprintf('The "revision" in "%s" must be a full lowercase commit hash.', $file));
        }

        return $revision;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function directory(array $data, string $file): ?string
    {
        $directory = $data['directory'] ?? null;
        if (null === $directory) {
            return null;
        }
        if (!\is_string($directory) || '' === $directory || 1 === preg_match('{^[/\\\\]|^[A-Za-z]:|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)}', $directory)) {
            throw new ConfigurationException(\sprintf('The "directory" in "%s" must be a relative path inside the repository.', $file));
        }

        return $directory;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function environment(array $data, string $file): string
    {
        $environment = $data['environment'] ?? 'dev';
        if (!\is_string($environment) || 1 !== preg_match('/^[A-Za-z0-9_]+$/', $environment)) {
            throw new ConfigurationException(\sprintf('The "environment" in "%s" must be a simple environment name.', $file));
        }

        return $environment;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string>            $setupIds
     */
    private function setup(array $data, array $setupIds, string $file): string
    {
        $setup = $data['setup'] ?? null;
        if (!\is_string($setup) || !\in_array($setup, $setupIds, true)) {
            throw new ConfigurationException(\sprintf('The "setup" in "%s" must be one of "%s".', $file, implode('", "', $setupIds)));
        }

        return $setup;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function ci(array $data, string $file): bool
    {
        $ci = $data['ci'] ?? null;
        if (!\is_bool($ci)) {
            throw new ConfigurationException(\sprintf('The configuration in "%s" must declare a boolean "ci".', $file));
        }

        return $ci;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function indexTimeout(array $data, string $file): int
    {
        $indexTimeout = $data['indexTimeout'] ?? self::DEFAULT_INDEX_TIMEOUT;
        if (!\is_int($indexTimeout) || $indexTimeout < 1 || $indexTimeout > self::MAX_INDEX_TIMEOUT) {
            throw new ConfigurationException(\sprintf('The "indexTimeout" in "%s" must be between 1 and %d seconds.', $file, self::MAX_INDEX_TIMEOUT));
        }

        return $indexTimeout;
    }
}
