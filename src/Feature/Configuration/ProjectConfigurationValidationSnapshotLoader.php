<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Project\Project;

final class ProjectConfigurationValidationSnapshotLoader
{
    public function __construct(private readonly ConfigurationValidationRegistry $validations)
    {
    }

    public function generation(Project $project): int
    {
        return $this->validations->generation($project);
    }

    /** @param array<array-key, mixed> $snapshot */
    public function load(Project $project, array $snapshot): void
    {
        $generation = $snapshot['configurationGeneration'] ?? 0;
        if (!\is_int($generation) || $generation !== $this->validations->generation($project)) {
            throw new StaleConfigurationValidationSnapshotException();
        }
        $validation = $snapshot['configurationValidation'] ?? null;
        $projectMetadata = $snapshot['project'] ?? null;
        $environment = \is_array($projectMetadata) ? ($projectMetadata['environment'] ?? null) : null;
        if (!\is_array($validation) || !\is_string($validation['status'] ?? null) || !\is_string($environment)) {
            $this->validations->replace($project, new ConfigurationValidationResult(ConfigurationValidationResult::UNAVAILABLE));

            return;
        }

        $state = $validation['status'];
        if (!\in_array($state, [ConfigurationValidationResult::VALID, ConfigurationValidationResult::INVALID, ConfigurationValidationResult::UNAVAILABLE], true)) {
            $state = ConfigurationValidationResult::UNAVAILABLE;
        }
        $line = $validation['line'] ?? null;
        $result = new ConfigurationValidationResult(
            $state,
            $environment,
            \is_string($validation['kind'] ?? null) ? $validation['kind'] : null,
            \is_string($validation['path'] ?? null) ? $validation['path'] : null,
            \is_string($validation['file'] ?? null) ? $validation['file'] : null,
            \is_int($line) && $line > 0 ? $line : null,
        );
        $this->validations->replace($project, $result);
        if (ConfigurationValidationResult::INVALID === $result->state) {
            throw new ConfigurationValidationException($result);
        }
    }
}
