<?php

namespace Symfony\Lsp\Tests\Runtime;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\ReportingRuntimeInitializer;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshMode;

final class ReportingRuntimeInitializerTest extends TestCase
{
    public function testReportsRefreshFailuresWithoutDiscardingTheServerSession(): void
    {
        $client = new ReportingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $statuses->runtimeReady($project);
        $statuses->runtimeFailed($project, new \RuntimeException('failed'));
        $initializer = new ReportingRuntimeInitializer(
            new class implements RuntimeInitializerInterface {
                public function initialize(Project $project, RuntimeRefreshMode $mode = RuntimeRefreshMode::Reuse, ?Cancellation $cancellation = null): void
                {
                    throw new \RuntimeException('secret=value');
                }
            },
            $client,
            $statuses,
        );

        $initializer->initialize($project);

        self::assertSame([[
            'method' => 'window/showMessage',
            'params' => [
                'type' => 1,
                'message' => 'Symfony LSP could not refresh runtime metadata for "/workspace". The last valid metadata remains active.',
            ],
        ]], $client->notifications);
    }

    public function testReportsInitialFailureAsStaticOnly(): void
    {
        $client = new ReportingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $statuses->runtimeFailed($project, new \RuntimeException('failed'));
        $initializer = new ReportingRuntimeInitializer(
            new class implements RuntimeInitializerInterface {
                public function initialize(Project $project, RuntimeRefreshMode $mode = RuntimeRefreshMode::Reuse, ?Cancellation $cancellation = null): void
                {
                    throw new \RuntimeException('secret=value');
                }
            },
            $client,
            $statuses,
        );

        $initializer->initialize($project);

        self::assertSame(
            'Symfony LSP could not initialize runtime metadata for "/workspace". Static-only features remain active.',
            $client->notifications[0]['params']['message'],
        );
    }
}

final class ReportingClient implements ClientInterface
{
    /** @var list<array{method: string, params: array<array-key, mixed>}> */
    public array $notifications = [];

    public function request(string $method, array $params): mixed
    {
        return null;
    }

    public function notify(string $method, array $params): void
    {
        $this->notifications[] = ['method' => $method, 'params' => $params];
    }
}
