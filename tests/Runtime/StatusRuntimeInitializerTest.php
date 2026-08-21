<?php

namespace Symfony\Lsp\Tests\Runtime;

use Amp\Cancellation;
use Amp\CancelledException;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;
use Symfony\Lsp\Runtime\StatusRuntimeInitializer;

final class StatusRuntimeInitializerTest extends TestCase
{
    public function testDoesNotRecordStatusForAProjectRemovedDuringTheRun(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
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
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
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
