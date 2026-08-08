<?php

namespace Symfony\Lsp\Tests\Runtime;

use Amp\Cancellation;
use Amp\Sync\LocalKeyedMutex;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;
use Symfony\Lsp\Runtime\SerializedRuntimeInitializer;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

final class SerializedRuntimeInitializerTest extends TestCase
{
    public function testSerializesRefreshesForTheSameProject(): void
    {
        $delegate = new ConcurrentRuntimeInitializer();
        $initializer = new SerializedRuntimeInitializer($delegate, new LocalKeyedMutex());
        $project = new Project('/workspace', 'file:///workspace', '^8.0');

        await([
            async(static fn () => $initializer->initialize($project)),
            async(static fn () => $initializer->initialize($project)),
        ]);

        self::assertSame(1, $delegate->maximumActive);
        self::assertSame(2, $delegate->refreshes);
    }
}

final class ConcurrentRuntimeInitializer implements RuntimeInitializerInterface
{
    public int $maximumActive = 0;
    public int $refreshes = 0;
    private int $active = 0;

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        ++$this->active;
        $this->maximumActive = max($this->maximumActive, $this->active);
        delay(0.01);
        ++$this->refreshes;
        --$this->active;
    }
}
