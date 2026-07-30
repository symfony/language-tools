<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\ReportingRuntimeInitializer;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;

final class ReportingRuntimeInitializerTest extends TestCase
{
    public function testReportsRefreshFailuresWithoutDiscardingTheServerSession(): void
    {
        $client = new ReportingClient();
        $initializer = new ReportingRuntimeInitializer(
            new class implements RuntimeInitializerInterface {
                public function initialize(Project $project): void
                {
                    throw new \RuntimeException('secret=value');
                }
            },
            $client,
        );

        $initializer->initialize(new Project('/workspace', 'file:///workspace', '^8.0'));

        self::assertSame([[
            'method' => 'window/showMessage',
            'params' => [
                'type' => 1,
                'message' => 'Symfony LSP could not refresh runtime metadata for "/workspace". The last valid metadata remains active.',
            ],
        ]], $client->notifications);
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
