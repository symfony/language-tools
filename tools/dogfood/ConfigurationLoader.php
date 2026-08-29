<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ConfigurationLoader
{
    private const VERSION = 1;
    private const KEYS = ['version', 'repository', 'revision', 'directory', 'environment', 'environmentVariables', 'setup', 'ci', 'indexTimeout', 'requestTimeout', 'probeRoots', 'probesPerCategory', 'allowPlugins', 'ignorePlatformRequirements', 'setupChanges'];
    private const DEFAULT_INDEX_TIMEOUT = 120;
    private const MAX_INDEX_TIMEOUT = 900;
    private const DEFAULT_REQUEST_TIMEOUT = 10;
    private const MAX_REQUEST_TIMEOUT = 120;
    private const MAX_PROBES_PER_CATEGORY = 10;

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
            $this->requestTimeout($data, $file),
            $this->probeRoots($data, $file),
            $this->probesPerCategory($data, $file),
            is_file($lockFile = substr($file, 0, -\strlen('.json')).'.lock') ? $lockFile : null,
            $this->allowPlugins($data, $file),
            $this->ignorePlatformRequirements($data, $file),
            $this->setupChanges($data, $file),
            $this->environmentVariables($data, $file),
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
        if (1 === preg_match('{://[^/@]*:[^/@]*@}', $repository)) {
            throw new ConfigurationException(\sprintf('The "repository" in "%s" must not embed credentials.', $file));
        }
        if (str_starts_with($repository, 'file://') || 1 !== preg_match('{^(?:[a-z][a-z0-9+.-]*://[^/]+/.|[^/@\s]+@[A-Za-z0-9.-]+:.)}', $repository)) {
            throw new ConfigurationException(\sprintf('The "repository" in "%s" must be a remote Git URL.', $file));
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
     *
     * @return array<string, string>
     */
    private function environmentVariables(array $data, string $file): array
    {
        $variables = $data['environmentVariables'] ?? [];
        if (!\is_array($variables) || ([] !== $variables && array_is_list($variables))) {
            throw new ConfigurationException(\sprintf('The "environmentVariables" in "%s" must be a map of environment variable names to string values.', $file));
        }
        foreach ($variables as $name => $value) {
            if (!\is_string($name) || 1 !== preg_match('/^[A-Z_][A-Z0-9_]*$/', $name) || !\is_string($value) || str_contains($value, "\0")) {
                throw new ConfigurationException(\sprintf('The "environmentVariables" in "%s" must be a map of environment variable names to string values.', $file));
            }
        }
        ksort($variables);

        return $variables;
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

    /**
     * @param array<array-key, mixed> $data
     */
    private function requestTimeout(array $data, string $file): int
    {
        $requestTimeout = $data['requestTimeout'] ?? self::DEFAULT_REQUEST_TIMEOUT;
        if (!\is_int($requestTimeout) || $requestTimeout < 1 || $requestTimeout > self::MAX_REQUEST_TIMEOUT) {
            throw new ConfigurationException(\sprintf('The "requestTimeout" in "%s" must be between 1 and %d seconds.', $file, self::MAX_REQUEST_TIMEOUT));
        }

        return $requestTimeout;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private function probeRoots(array $data, string $file): array
    {
        $probeRoots = $data['probeRoots'] ?? ProbeFinder::DEFAULT_ROOTS;
        if (!\is_array($probeRoots) || [] === $probeRoots || !array_is_list($probeRoots)) {
            throw new ConfigurationException(\sprintf('The "probeRoots" in "%s" must be a non-empty list of relative paths.', $file));
        }
        foreach ($probeRoots as $root) {
            if (!\is_string($root) || '' === $root || 1 === preg_match('{^[/\\\\]|^[A-Za-z]:|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)|,}', $root)) {
                throw new ConfigurationException(\sprintf('The "probeRoots" in "%s" must be a non-empty list of relative paths.', $file));
            }
        }

        return $probeRoots;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private function allowPlugins(array $data, string $file): array
    {
        $allowPlugins = $data['allowPlugins'] ?? [];
        if (!\is_array($allowPlugins) || !array_is_list($allowPlugins)) {
            throw new ConfigurationException(\sprintf('The "allowPlugins" in "%s" must be a list of Composer plugin names.', $file));
        }
        foreach ($allowPlugins as $plugin) {
            if (!\is_string($plugin) || 1 !== preg_match('{^[a-z0-9._-]+/[a-z0-9._-]+$}', $plugin)) {
                throw new ConfigurationException(\sprintf('The "allowPlugins" in "%s" must be a list of Composer plugin names.', $file));
            }
        }

        return $allowPlugins;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private function setupChanges(array $data, string $file): array
    {
        $setupChanges = $data['setupChanges'] ?? [];
        if (!\is_array($setupChanges) || !array_is_list($setupChanges)) {
            throw new ConfigurationException(\sprintf('The "setupChanges" in "%s" must be a list of relative paths.', $file));
        }
        foreach ($setupChanges as $path) {
            if (!\is_string($path) || '' === $path || 1 === preg_match('{^[/\\\\]|^[A-Za-z]:|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)}', $path)) {
                throw new ConfigurationException(\sprintf('The "setupChanges" in "%s" must be a list of relative paths.', $file));
            }
        }

        return $setupChanges;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private function ignorePlatformRequirements(array $data, string $file): array
    {
        $requirements = $data['ignorePlatformRequirements'] ?? [];
        if (!\is_array($requirements) || !array_is_list($requirements)) {
            throw new ConfigurationException(\sprintf('The "ignorePlatformRequirements" in "%s" must be a list of platform package names.', $file));
        }
        foreach ($requirements as $requirement) {
            if (!\is_string($requirement) || 1 !== preg_match('{^(?:php(?:-64bit)?|(?:ext|lib)-[a-z0-9_.-]+)$}', $requirement)) {
                throw new ConfigurationException(\sprintf('The "ignorePlatformRequirements" in "%s" must be a list of platform package names.', $file));
            }
        }

        return $requirements;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function probesPerCategory(array $data, string $file): int
    {
        $probesPerCategory = $data['probesPerCategory'] ?? 1;
        if (!\is_int($probesPerCategory) || $probesPerCategory < 1 || $probesPerCategory > self::MAX_PROBES_PER_CATEGORY) {
            throw new ConfigurationException(\sprintf('The "probesPerCategory" in "%s" must be between 1 and %d.', $file, self::MAX_PROBES_PER_CATEGORY));
        }

        return $probesPerCategory;
    }
}
