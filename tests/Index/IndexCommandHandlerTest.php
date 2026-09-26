<?php

namespace Symfony\Lsp\Tests\Index;

use Amp\Cancellation;
use Amp\Sync\LocalKeyedMutex;
use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Feature\Configuration\StaleConfigurationValidationSnapshotException;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\IndexCommandHandler;
use Symfony\Lsp\Index\PhpParseHealthResolver;
use Symfony\Lsp\Index\PhpRuntimeStructureHasher;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Index\SourceIndexFileProcessor;
use Symfony\Lsp\Index\SourceIndexOverlayManager;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Index\SourceIndexProviderPipeline;
use Symfony\Lsp\Index\SourceOverlayHealthRegistry;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\GlobPatternCompiler;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\TrustStatus;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Project\WorkspaceTrust;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshMode;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;
use Symfony\Lsp\Runtime\StatusRuntimeInitializer;
use Symfony\Lsp\Server\SensitiveDataRedactor;
use Symfony\Lsp\Server\ServerLogger;
use Symfony\Lsp\Tests\Support\InMemorySourceIndexStore;
use Symfony\Lsp\Tests\Support\NullProgressReporter;
use Symfony\Lsp\Tests\Support\ProjectPaths;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class IndexCommandHandlerTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-');
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testManuallyRefreshesSourceAndTrustedRuntimeIndexes(): void
    {
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project(
            $this->workspace->rootPath,
            'file://'.$this->workspace->rootPath,
        )]);
        $statuses = new ProjectIndexStatusRegistry();
        $sourceScanner = $this->scanner($projects, $statuses);
        $runtime = new RecordingRuntimeInitializer();
        $workspaceTrust = new WorkspaceTrust();
        $workspaceTrust->set($project, TrustStatus::Trusted);
        $runtimeConfiguration = new RuntimeConfiguration();
        $handler = new IndexCommandHandler(
            $projects,
            $workspaceTrust,
            $sourceScanner,
            new StatusRuntimeInitializer($runtime, $statuses, $projects),
            $statuses,
            $runtimeConfiguration,
            new AnalysisSettings(),
        );

        $result = $handler->execute([
            'command' => IndexCommandHandler::REFRESH_COMMAND,
            'arguments' => [$project->rootUri],
        ]);

        self::assertSame([$this->workspace->rootPath], $runtime->projects);
        self::assertSame([[
            'root' => $this->workspace->rootPath,
            'source' => ['state' => 'ready'],
            'runtime' => ['state' => 'ready'],
            'environment' => 'dev',
            'kernel' => null,
            'runtimeEnabled' => true,
            'trusted' => true,
        ]], $result);
        self::assertSame($result, $handler->execute([
            'command' => IndexCommandHandler::STATUS_COMMAND,
        ]));

        $switched = $handler->execute([
            'command' => IndexCommandHandler::SWITCH_ENVIRONMENT_COMMAND,
            'arguments' => [$project->rootUri, 'test'],
        ]);
        self::assertSame('test', $runtimeConfiguration->environment($project));
        self::assertSame('test', $switched[0]['environment'] ?? null);
        self::assertSame(RuntimeRefreshMode::Clear, $runtime->plans[1]->mode());

        foreach (["prod\n", 'prod env', ''] as $invalid) {
            self::assertNull($handler->execute([
                'command' => IndexCommandHandler::SWITCH_ENVIRONMENT_COMMAND,
                'arguments' => [$project->rootUri, $invalid],
            ]));
        }
        self::assertSame('test', $runtimeConfiguration->environment($project));

        $workspaceTrust->set($project, TrustStatus::Untrusted);
        $untrusted = $handler->execute([
            'command' => IndexCommandHandler::STATUS_COMMAND,
            'arguments' => [$project->rootUri],
        ]);
        self::assertFalse($untrusted[0]['trusted'] ?? null);
    }

    public function testSwitchesTheAnalyzedKernelAndReturnsToAutomaticDetection(): void
    {
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project(
            $this->workspace->rootPath,
            'file://'.$this->workspace->rootPath,
        )]);
        $statuses = new ProjectIndexStatusRegistry();
        $runtime = new RecordingRuntimeInitializer();
        $workspaceTrust = new WorkspaceTrust();
        $workspaceTrust->set($project, TrustStatus::Trusted);
        $runtimeConfiguration = new RuntimeConfiguration();
        $handler = new IndexCommandHandler(
            $projects,
            $workspaceTrust,
            $this->scanner($projects, $statuses),
            new StatusRuntimeInitializer($runtime, $statuses, $projects),
            $statuses,
            $runtimeConfiguration,
            new AnalysisSettings(),
        );

        $switched = $handler->execute([
            'command' => IndexCommandHandler::SWITCH_KERNEL_COMMAND,
            'arguments' => [$project->rootUri, 'Api\\Kernel'],
        ]);

        self::assertSame('Api\\Kernel', $runtimeConfiguration->kernel($project));
        self::assertSame('Api\\Kernel', $switched[0]['kernel'] ?? null);
        self::assertSame(RuntimeRefreshMode::Clear, $runtime->plans[0]->mode());

        $entryPoint = $handler->execute([
            'command' => IndexCommandHandler::SWITCH_KERNEL_COMMAND,
            'arguments' => [$project->rootUri, './bin/websiteconsole'],
        ]);
        self::assertSame('bin/websiteconsole', $entryPoint[0]['kernel'] ?? null);

        self::assertNull($handler->execute([
            'command' => IndexCommandHandler::SWITCH_KERNEL_COMMAND,
            'arguments' => [$project->rootUri, '../outside/bin/console'],
        ]));
        self::assertSame('bin/websiteconsole', $runtimeConfiguration->kernel($project));

        $automatic = $handler->execute([
            'command' => IndexCommandHandler::SWITCH_KERNEL_COMMAND,
            'arguments' => [$project->rootUri, ''],
        ]);
        self::assertNull($runtimeConfiguration->kernel($project));
        self::assertNull($automatic[0]['kernel'] ?? null);
    }

    public function testRetriesManualRuntimeRefreshAfterAConfigurationChangeInvalidatesTheSnapshot(): void
    {
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project(
            $this->workspace->rootPath,
            'file://'.$this->workspace->rootPath,
        )]);
        $statuses = new ProjectIndexStatusRegistry();
        $sourceScanner = $this->scanner($projects, $statuses);
        $runtime = new RecordingRuntimeInitializer(1);
        $workspaceTrust = new WorkspaceTrust();
        $workspaceTrust->set($project, TrustStatus::Trusted);
        $handler = new IndexCommandHandler(
            $projects,
            $workspaceTrust,
            $sourceScanner,
            new StatusRuntimeInitializer($runtime, $statuses, $projects),
            $statuses,
            new RuntimeConfiguration(),
            new AnalysisSettings(),
        );

        $result = $handler->execute([
            'command' => IndexCommandHandler::REFRESH_COMMAND,
            'arguments' => [$project->rootUri],
        ]);

        self::assertSame([$this->workspace->rootPath, $this->workspace->rootPath], $runtime->projects);
        self::assertSame('ready', $result[0]['runtime']['state'] ?? null);
    }

    private function scanner(ProjectRegistry $projects, ProjectIndexStatusRegistry $statuses): ApplicationSourceScanner
    {
        $documents = new DocumentStore();
        $store = new InMemorySourceIndexStore();
        $files = ProjectPaths::enumerator(new ProjectFileScopeRegistry(new GlobPatternCompiler()));
        $pipeline = new SourceIndexProviderPipeline(new SourceIndexPayloadCodec(), []);
        $health = new SourceOverlayHealthRegistry();

        return new ApplicationSourceScanner(
            $projects,
            $statuses,
            new NullProgressReporter(),
            $store,
            ProjectPaths::policy(),
            $files,
            new LocalKeyedMutex(),
            new ServerLogger(null, new SensitiveDataRedactor()),
            $pipeline,
            new SourceIndexFileProcessor($store, $pipeline, new PhpRuntimeStructureHasher()),
            new SourceIndexOverlayManager(
                $projects,
                $documents,
                new UriToPathConverter(),
                ProjectPaths::policy(),
                $files,
                $pipeline,
                new PhpParseHealthResolver(new TolerantPhpParser(new Parser())),
                $health,
            ),
        );
    }
}

final class RecordingRuntimeInitializer implements RuntimeInitializerInterface
{
    /** @var list<string> */
    public array $projects = [];

    /** @var list<RuntimeRefreshPlan> */
    public array $plans = [];

    public function __construct(private int $staleSnapshots = 0)
    {
    }

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        $this->projects[] = $project->rootPath;
        $this->plans[] = $plan ?? new RuntimeRefreshPlan();
        if (0 < $this->staleSnapshots) {
            --$this->staleSnapshots;

            throw new StaleConfigurationValidationSnapshotException();
        }
    }
}
