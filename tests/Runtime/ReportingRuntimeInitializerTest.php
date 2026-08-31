<?php

namespace Symfony\Lsp\Tests\Runtime;

use Amp\ByteStream\WritableBuffer;
use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\ReportingRuntimeInitializer;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;
use Symfony\Lsp\Runtime\UnsupportedSymfonyVersionException;
use Symfony\Lsp\Server\SensitiveDataRedactor;
use Symfony\Lsp\Server\ServerLogger;

final class ReportingRuntimeInitializerTest extends TestCase
{
    public function testReportsRefreshFailuresWithoutDiscardingTheServerSession(): void
    {
        $client = new ReportingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $statuses->runtimeReady($project);
        $statuses->runtimeFailed($project);
        $initializer = new ReportingRuntimeInitializer($this->failingInitializer(), $client, $statuses, new ServerLogger(null, new SensitiveDataRedactor()));

        $initializer->initialize($project);

        self::assertSame([[
            'method' => 'window/showMessage',
            'params' => [
                'type' => 1,
                'message' => 'Symfony Language Tools could not refresh runtime metadata for "/workspace". The last valid metadata remains active.',
            ],
        ]], $client->notifications);
    }

    public function testReportsInitialFailureAsStaticOnly(): void
    {
        $client = new ReportingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $statuses->runtimeFailed($project);
        $initializer = new ReportingRuntimeInitializer($this->failingInitializer(), $client, $statuses, new ServerLogger(null, new SensitiveDataRedactor()));

        $initializer->initialize($project);

        self::assertSame(
            'Symfony Language Tools could not initialize runtime metadata for "/workspace". Static-only features remain active.',
            $client->notifications[0]['params']['message'],
        );
    }

    public function testReportsUnsupportedSymfonyVersionAsStaticOnly(): void
    {
        $client = new ReportingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^5.4');
        $statuses->runtimeFailed($project);
        $initializer = new ReportingRuntimeInitializer(
            $this->failingInitializer(new UnsupportedSymfonyVersionException('5.4')),
            $client,
            $statuses,
            new ServerLogger(null, new SensitiveDataRedactor()),
        );

        $initializer->initialize($project);

        self::assertSame(
            'The project "/workspace" uses Symfony 5.4, which is not supported by Symfony Language Tools. Static-only features remain active.',
            $client->notifications[0]['params']['message'],
        );
    }

    public function testReportsConfigurationFailuresWithoutRawDetails(): void
    {
        $client = new ReportingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $statuses->runtimeFailed($project, 'configuration');
        $initializer = new ReportingRuntimeInitializer($this->failingInitializer(), $client, $statuses, new ServerLogger(null, new SensitiveDataRedactor()));

        $initializer->initialize($project);

        self::assertSame(
            'Symfony Language Tools found invalid application configuration for "/workspace".',
            $client->notifications[0]['params']['message'],
        );
    }

    public function testReportsConfigurationFailuresWithRestoredMetadata(): void
    {
        $client = new ReportingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $statuses->runtimeReady($project);
        $statuses->runtimeFailed($project, 'configuration');
        $initializer = new ReportingRuntimeInitializer($this->failingInitializer(), $client, $statuses, new ServerLogger(null, new SensitiveDataRedactor()));

        $initializer->initialize($project);

        self::assertSame(
            'Symfony Language Tools found invalid application configuration for "/workspace". The last valid runtime metadata remains active.',
            $client->notifications[0]['params']['message'],
        );
    }

    public function testLogsTheUnderlyingErrorWithRedaction(): void
    {
        $client = new ReportingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $statuses->runtimeFailed($project);
        $log = new WritableBuffer();
        $initializer = new ReportingRuntimeInitializer($this->failingInitializer(), $client, $statuses, new ServerLogger($log, new SensitiveDataRedactor()));

        $initializer->initialize($project);

        $log->close();
        self::assertSame("[error] secret=[redacted]\n", $log->buffer());
        self::assertSame(['window/showMessage'], array_column($client->notifications, 'method'));
    }

    private function failingInitializer(?\Throwable $error = null): RuntimeInitializerInterface
    {
        return new class($error ?? new \RuntimeException('secret=value')) implements RuntimeInitializerInterface {
            public function __construct(private readonly \Throwable $error)
            {
            }

            public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
            {
                throw $this->error;
            }
        };
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
