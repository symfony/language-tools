<?php

namespace Symfony\Lsp\Tests\Runtime;

use Amp\ByteStream\WritableBuffer;
use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\PartialRuntimeMetadataException;
use Symfony\Lsp\Runtime\ReportingRuntimeInitializer;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;
use Symfony\Lsp\Runtime\UnsupportedSymfonyVersionException;
use Symfony\Lsp\Server\SensitiveDataRedactor;
use Symfony\Lsp\Server\ServerLogger;
use Symfony\Lsp\Tests\Support\RecordingClient;

final class ReportingRuntimeInitializerTest extends TestCase
{
    public function testReportsRefreshFailuresWithoutDiscardingTheServerSession(): void
    {
        $client = new RecordingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $statuses->runtimeReady($project);
        $statuses->runtimeFailed($project);
        $initializer = new ReportingRuntimeInitializer($this->failingInitializer(), $client, $statuses, new ServerLogger(null, new SensitiveDataRedactor()));

        $initializer->initialize($project, RuntimeRefreshPlan::reuse());

        self::assertSame([[
            'method' => 'window/showMessage',
            'params' => [
                'type' => 1,
                'message' => 'Symfony Language Tools could not refresh runtime metadata for "/workspace". The last valid metadata remains active.',
            ],
        ]], $client->notifications);
    }

    public function testReportsPartialRuntimeMetadataWithoutHidingAvailableFeatures(): void
    {
        $client = new RecordingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $statuses->runtimeFailed($project);
        $initializer = new ReportingRuntimeInitializer(
            $this->failingInitializer(new PartialRuntimeMetadataException(['twig'])),
            $client,
            $statuses,
            new ServerLogger(null, new SensitiveDataRedactor()),
        );

        $initializer->initialize($project, RuntimeRefreshPlan::reuse());

        self::assertSame(
            'Symfony Language Tools could not load 1 runtime metadata section for "/workspace": twig. Other runtime-backed features remain active.',
            $client->notifications[0]['params']['message'],
        );
    }

    public function testLogsSanitizedSectionCausesWithTheFailureItself(): void
    {
        $client = new RecordingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $statuses->runtimeFailed($project);
        $error = new PartialRuntimeMetadataException(['twig'], [[
            'section' => 'twig',
            'chain' => [[
                'class' => \RuntimeException::class,
                'message' => 'DATABASE_URL=mysql://user:pass@database',
                'origin' => 'src/TwigExtension.php:24',
                'frames' => ['App\\TwigExtension->getFunctions (src/TwigExtension.php:20)'],
            ]],
        ]]);
        $log = new WritableBuffer();
        $logger = new ServerLogger($log, new SensitiveDataRedactor());
        $initializer = new ReportingRuntimeInitializer(
            $this->failingInitializer($error),
            $client,
            $statuses,
            $logger,
        );

        $initializer->initialize($project, RuntimeRefreshPlan::reuse());

        $log->close();
        self::assertStringContainsString(
            "[error] The project bridge could not load runtime metadata: twig.\n"
            ."Runtime section \"twig\": RuntimeException at src/TwigExtension.php:24: [redacted]\n"
            .'  at App\\TwigExtension->getFunctions (src/TwigExtension.php:20)',
            $log->buffer(),
        );
        self::assertStringNotContainsString('user:pass', $log->buffer());
        self::assertStringNotContainsString('ReportingRuntimeInitializer.php', $log->buffer());
        $message = $client->notifications[0]['params']['message'] ?? null;
        self::assertIsString($message);
        self::assertStringNotContainsString('DATABASE_URL', $message);
    }

    public function testReportsInitialFailureAsStaticOnly(): void
    {
        $client = new RecordingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $statuses->runtimeFailed($project);
        $initializer = new ReportingRuntimeInitializer($this->failingInitializer(), $client, $statuses, new ServerLogger(null, new SensitiveDataRedactor()));

        $initializer->initialize($project, RuntimeRefreshPlan::reuse());

        self::assertSame(
            'Symfony Language Tools could not initialize runtime metadata for "/workspace". Static-only features remain active.',
            $client->notifications[0]['params']['message'],
        );
    }

    public function testReportsUnsupportedSymfonyVersionAsStaticOnly(): void
    {
        $client = new RecordingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $statuses->runtimeFailed($project);
        $initializer = new ReportingRuntimeInitializer(
            $this->failingInitializer(new UnsupportedSymfonyVersionException('5.4')),
            $client,
            $statuses,
            new ServerLogger(null, new SensitiveDataRedactor()),
        );

        $initializer->initialize($project, RuntimeRefreshPlan::reuse());

        self::assertSame(
            'The project "/workspace" uses Symfony 5.4, which is not supported by Symfony Language Tools. Static-only features remain active.',
            $client->notifications[0]['params']['message'],
        );
    }

    public function testReportsConfigurationFailuresWithoutRawDetails(): void
    {
        $client = new RecordingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $statuses->runtimeFailed($project, 'configuration');
        $initializer = new ReportingRuntimeInitializer($this->failingInitializer(), $client, $statuses, new ServerLogger(null, new SensitiveDataRedactor()));

        $initializer->initialize($project, RuntimeRefreshPlan::reuse());

        self::assertSame(
            'Symfony Language Tools found invalid application configuration for "/workspace".',
            $client->notifications[0]['params']['message'],
        );
    }

    public function testReportsConfigurationFailuresWithRestoredMetadata(): void
    {
        $client = new RecordingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $statuses->runtimeReady($project);
        $statuses->runtimeFailed($project, 'configuration');
        $initializer = new ReportingRuntimeInitializer($this->failingInitializer(), $client, $statuses, new ServerLogger(null, new SensitiveDataRedactor()));

        $initializer->initialize($project, RuntimeRefreshPlan::reuse());

        self::assertSame(
            'Symfony Language Tools found invalid application configuration for "/workspace". The last valid runtime metadata remains active.',
            $client->notifications[0]['params']['message'],
        );
    }

    public function testLogsTheUnderlyingErrorWithRedaction(): void
    {
        $client = new RecordingClient();
        $statuses = new ProjectIndexStatusRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $statuses->runtimeFailed($project);
        $log = new WritableBuffer();
        $initializer = new ReportingRuntimeInitializer($this->failingInitializer(), $client, $statuses, new ServerLogger($log, new SensitiveDataRedactor()));

        $initializer->initialize($project, RuntimeRefreshPlan::reuse());

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

            public function initialize(Project $project, RuntimeRefreshPlan $plan, ?Cancellation $cancellation = null): void
            {
                throw $this->error;
            }
        };
    }
}
