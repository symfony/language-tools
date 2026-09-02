<?php

namespace Symfony\Lsp\Tests\Runtime;

use Amp\Cancellation;
use Amp\CancelledException;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Configuration\ConfigurationValidationException;
use Symfony\Lsp\Feature\Configuration\ConfigurationValidationResult;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Runtime\BridgeExecutionException;
use Symfony\Lsp\Runtime\PartialRuntimeMetadataException;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;
use Symfony\Lsp\Runtime\StatusRuntimeInitializer;

final class StatusRuntimeInitializerTest extends TestCase
{
    public function testDoesNotRecordStatusForAProjectRemovedDuringTheRun(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $registry = new ProjectRegistry();
        $registry->replace([$project]);
        $statuses = new ProjectIndexStatusRegistry();
        $initializer = new StatusRuntimeInitializer(
            new RemovingThrowingInitializer($registry, $statuses, new CancelledException()),
            $statuses,
            $registry,
        );

        try {
            $initializer->initialize($project);
            self::fail('The cancellation should have propagated.');
        } catch (CancelledException) {
        }

        self::assertSame('not-indexed', $statuses->status($project)['runtime']['state']);
    }

    public function testDoesNotRecordFailuresForAProjectRemovedDuringTheRun(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $registry = new ProjectRegistry();
        $registry->replace([$project]);
        $statuses = new ProjectIndexStatusRegistry();
        $initializer = new StatusRuntimeInitializer(
            new RemovingThrowingInitializer($registry, $statuses, new \RuntimeException('The bridge failed.')),
            $statuses,
            $registry,
        );

        try {
            $initializer->initialize($project);
            self::fail('The failure should have propagated.');
        } catch (\RuntimeException) {
        }

        self::assertSame('not-indexed', $statuses->status($project)['runtime']['state']);
    }

    public function testRecordsTheBootstrapStageForBridgeExecutionFailures(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $registry = new ProjectRegistry();
        $registry->replace([$project]);
        $statuses = new ProjectIndexStatusRegistry();
        $initializer = new StatusRuntimeInitializer(
            new ThrowingInitializer(new BridgeExecutionException('The project bridge failed with status 1.')),
            $statuses,
            $registry,
        );

        try {
            $initializer->initialize($project);
            self::fail('The failure should have propagated.');
        } catch (BridgeExecutionException) {
        }

        self::assertSame(
            ['state' => 'failed', 'error' => 'The application failed to boot.', 'stage' => 'bootstrap'],
            $statuses->status($project)['runtime'],
        );
    }

    public function testRecordsTheConfigurationStageForValidationFailures(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $registry = new ProjectRegistry();
        $registry->replace([$project]);
        $statuses = new ProjectIndexStatusRegistry();
        $initializer = new StatusRuntimeInitializer(
            new ThrowingInitializer(new ConfigurationValidationException(new ConfigurationValidationResult(ConfigurationValidationResult::INVALID, 'dev'))),
            $statuses,
            $registry,
        );

        try {
            $initializer->initialize($project);
            self::fail('The failure should have propagated.');
        } catch (ConfigurationValidationException) {
        }

        self::assertSame(
            ['state' => 'failed', 'error' => 'The application configuration is invalid.', 'stage' => 'configuration'],
            $statuses->status($project)['runtime'],
        );
    }

    public function testRecordsPartialRuntimeMetadataAsAvailable(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $registry = new ProjectRegistry();
        $registry->replace([$project]);
        $statuses = new ProjectIndexStatusRegistry();
        $initializer = new StatusRuntimeInitializer(
            new ThrowingInitializer(new PartialRuntimeMetadataException(['twig'])),
            $statuses,
            $registry,
        );

        try {
            $initializer->initialize($project);
            self::fail('The partial failure should have propagated.');
        } catch (PartialRuntimeMetadataException) {
        }

        self::assertSame(
            ['state' => 'partial', 'error' => 'Some runtime metadata could not be loaded.'],
            $statuses->status($project)['runtime'],
        );
    }

    public function testRecordsNoStageForOtherRuntimeFailures(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $registry = new ProjectRegistry();
        $registry->replace([$project]);
        $statuses = new ProjectIndexStatusRegistry();
        $initializer = new StatusRuntimeInitializer(
            new ThrowingInitializer(new \RuntimeException('The project bridge returned an unsupported snapshot.')),
            $statuses,
            $registry,
        );

        try {
            $initializer->initialize($project);
            self::fail('The failure should have propagated.');
        } catch (\RuntimeException) {
        }

        self::assertSame(
            ['state' => 'failed', 'error' => 'Runtime indexing failed.'],
            $statuses->status($project)['runtime'],
        );
    }
}

final class ThrowingInitializer implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly \Throwable $error,
    ) {
    }

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        throw $this->error;
    }
}

final class RemovingThrowingInitializer implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly ProjectRegistry $registry,
        private readonly ProjectIndexStatusRegistry $statuses,
        private readonly \Throwable $error,
    ) {
    }

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        // mimic the workspace change: the registry shrinks and the cleaner runs
        $this->registry->replace([]);
        $this->statuses->removeProject($project);

        throw $this->error;
    }
}
