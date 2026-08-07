<?php

namespace Symfony\Lsp\Tests\Runtime;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\ObservedRuntimeInitializer;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshObserverInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;

final class ObservedRuntimeInitializerTest extends TestCase
{
    public function testNotifiesObserverAfterRuntimeInitialization(): void
    {
        $initializer = new ObservedRuntimeInitializer(
            new SuccessfulRuntimeInitializer(),
            $observer = new CapturingRuntimeRefreshObserver(),
        );

        $initializer->initialize(new Project('/workspace', 'file:///workspace', '^8.0'));

        self::assertSame(['/workspace'], $observer->projects);
    }
}

final class SuccessfulRuntimeInitializer implements RuntimeInitializerInterface
{
    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
    }
}

final class CapturingRuntimeRefreshObserver implements RuntimeRefreshObserverInterface
{
    /** @var list<string> */
    public array $projects = [];

    public function refreshed(Project $project): void
    {
        $this->projects[] = $project->rootPath();
    }
}
