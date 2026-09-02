<?php

namespace Symfony\Lsp\Tests\Feature\Configuration;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Configuration\ConfigurationValidationException;
use Symfony\Lsp\Feature\Configuration\ConfigurationValidationRegistry;
use Symfony\Lsp\Feature\Configuration\ConfigurationValidationResult;
use Symfony\Lsp\Feature\Configuration\ProjectConfigurationValidationSnapshotLoader;
use Symfony\Lsp\Feature\Configuration\StaleConfigurationValidationSnapshotException;
use Symfony\Lsp\Project\Project;

final class ConfigurationValidationRegistryTest extends TestCase
{
    public function testLoadsAndReplacesValidationResults(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $registry = new ConfigurationValidationRegistry();
        $loader = new ProjectConfigurationValidationSnapshotLoader($registry);

        try {
            $loader->load($project, [
                'project' => ['environment' => 'test'],
                'configurationValidation' => [
                    'status' => 'invalid',
                    'kind' => 'invalid_type',
                    'path' => 'framework.router.utf8',
                    'file' => 'config/packages/framework.yaml',
                    'line' => 8,
                ],
            ]);
            self::fail('Invalid configuration validation was accepted.');
        } catch (ConfigurationValidationException $error) {
            self::assertSame('invalid_type', $error->validation->kind);
        }

        $result = $registry->result($project);
        self::assertSame(ConfigurationValidationResult::INVALID, $result->state);
        self::assertSame('test', $result->environment);
        self::assertSame('invalid_type', $result->kind);
        self::assertSame('framework.router.utf8', $result->path);
        self::assertSame('config/packages/framework.yaml', $result->file);
        self::assertSame(8, $result->line);

        $loader->load($project, [
            'project' => ['environment' => 'dev'],
            'configurationValidation' => ['status' => 'valid'],
        ]);
        self::assertSame(ConfigurationValidationResult::VALID, $registry->result($project)->state);

        $registry->pending($project);
        self::assertSame(ConfigurationValidationResult::PENDING, $registry->result($project)->state);

        $registry->removeProject($project);
        self::assertSame(ConfigurationValidationResult::UNAVAILABLE, $registry->result($project)->state);
    }

    public function testRejectsResultsFromAnOlderConfigurationGeneration(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $registry = new ConfigurationValidationRegistry();
        $registry->pending($project);
        $loader = new ProjectConfigurationValidationSnapshotLoader($registry);

        try {
            $loader->load($project, [
                'configurationGeneration' => 0,
                'project' => ['environment' => 'dev'],
                'configurationValidation' => ['status' => 'valid'],
            ]);
            self::fail('The stale configuration validation was accepted.');
        } catch (StaleConfigurationValidationSnapshotException) {
        }

        self::assertSame(ConfigurationValidationResult::PENDING, $registry->result($project)->state);
        self::assertSame(1, $registry->generation($project));
    }

    public function testRejectsMalformedValidationPayloads(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $registry = new ConfigurationValidationRegistry();
        (new ProjectConfigurationValidationSnapshotLoader($registry))->load($project, [
            'project' => ['environment' => 'dev'],
            'configurationValidation' => ['status' => 'unexpected', 'line' => -1],
        ]);

        self::assertSame(ConfigurationValidationResult::UNAVAILABLE, $registry->result($project)->state);
    }
}
