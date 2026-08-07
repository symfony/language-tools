<?php

namespace Symfony\Lsp\Tests\Index;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\IndexCommandHandler;
use Symfony\Lsp\Index\PhpRuntimeStructureHasher;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\TrustStatus;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshMode;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;
use Symfony\Lsp\Runtime\StatusRuntimeInitializer;
use Symfony\Lsp\Tests\Support\InMemorySourceIndexStore;
use Symfony\Lsp\Tests\Support\NullProgressReporter;

final class IndexCommandHandlerTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        @rmdir($this->temporaryDirectory);
    }

    public function testManuallyRefreshesSourceAndTrustedRuntimeIndexes(): void
    {
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project(
            $this->temporaryDirectory,
            'file://'.$this->temporaryDirectory,
            '^8.0',
        )]);
        $statuses = new ProjectIndexStatusRegistry();
        $sourceScanner = new ApplicationSourceScanner(
            $projects,
            new DocumentStore(),
            $statuses,
            new NullProgressReporter(),
            new InMemorySourceIndexStore(),
            new SourceIndexPayloadCodec(),
            new PhpRuntimeStructureHasher(),
            new UriToPathConverter(),
            [],
        );
        $runtime = new RecordingRuntimeInitializer();
        $workspaceTrust = new WorkspaceTrust();
        $workspaceTrust->set($project, TrustStatus::Trusted);
        $runtimeConfiguration = new RuntimeConfiguration();
        $handler = new IndexCommandHandler(
            $projects,
            $workspaceTrust,
            $sourceScanner,
            new StatusRuntimeInitializer($runtime, $statuses),
            $statuses,
            $runtimeConfiguration,
        );

        $result = $handler->execute([
            'command' => IndexCommandHandler::REFRESH_COMMAND,
            'arguments' => [$project->rootUri()],
        ]);

        self::assertSame([$this->temporaryDirectory], $runtime->projects);
        self::assertSame([[
            'root' => $this->temporaryDirectory,
            'source' => ['state' => 'ready'],
            'runtime' => ['state' => 'ready'],
            'environment' => 'dev',
            'runtimeEnabled' => true,
            'trusted' => true,
        ]], $result);
        self::assertSame($result, $handler->execute([
            'command' => IndexCommandHandler::STATUS_COMMAND,
        ]));

        $switched = $handler->execute([
            'command' => IndexCommandHandler::SWITCH_ENVIRONMENT_COMMAND,
            'arguments' => [$project->rootUri(), 'test'],
        ]);
        self::assertSame('test', $runtimeConfiguration->environment($project));
        self::assertSame('test', $switched[0]['environment'] ?? null);
        self::assertSame(RuntimeRefreshMode::Clear, $runtime->plans[1]->mode());

        $workspaceTrust->set($project, TrustStatus::Untrusted);
        $untrusted = $handler->execute([
            'command' => IndexCommandHandler::STATUS_COMMAND,
            'arguments' => [$project->rootUri()],
        ]);
        self::assertFalse($untrusted[0]['trusted'] ?? null);
    }
}

final class RecordingRuntimeInitializer implements RuntimeInitializerInterface
{
    /** @var list<string> */
    public array $projects = [];

    /** @var list<RuntimeRefreshPlan> */
    public array $plans = [];

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        $this->projects[] = $project->rootPath();
        $this->plans[] = $plan ?? new RuntimeRefreshPlan();
    }
}
