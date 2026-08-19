<?php

namespace Symfony\Lsp\Tests\Runtime;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Feature\DependencyInjection\ParameterIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\ProjectServiceSnapshotLoader;
use Symfony\Lsp\Feature\DependencyInjection\ServiceIndexRegistry;
use Symfony\Lsp\Feature\Route\ProjectRouteSnapshotLoader;
use Symfony\Lsp\Feature\Route\Route;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\BridgeInstaller;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\ProcessResult;
use Symfony\Lsp\Runtime\ProcessRunnerInterface;
use Symfony\Lsp\Runtime\ProjectRuntimeInitializer;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeRefreshMode;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderRegistry;

final class ProjectRuntimeInitializerTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testExecutesBridgeAndLoadsRoutes(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $processRunner = new CapturingProcessRunner(new ProcessResult(0, json_encode([
            'schemaVersion' => 1,
            'sections' => [
                'routes' => [
                    'complete' => true,
                    'items' => [
                        ['name' => 'homepage', 'path' => '/'],
                    ],
                ],
                'container' => [
                    'complete' => true,
                    'items' => [
                        ['id' => 'app.mailer', 'class' => 'App\\Mailer'],
                    ],
                    'parameters' => [
                        ['name' => 'app.storage_dir'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR), ''));
        $indexes = new RouteIndexRegistry();
        $serviceIndexes = new ServiceIndexRegistry();
        $parameterIndexes = new ParameterIndexRegistry();
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $configuration = new RuntimeConfiguration();
        $configuration->configure([
            'phpCommand' => ['project-php', '--flag'],
            'environment' => 'test',
            'bridgeTimeout' => 90,
        ]);
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test', new Filesystem()),
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([
                new ProjectRouteSnapshotLoader($indexes),
                new ProjectServiceSnapshotLoader($serviceIndexes, $parameterIndexes),
            ]),
            $configuration,
            new ContainerPathMapper($configuration),
        );

        $initializer->initialize($project);

        self::assertSame('homepage', $indexes->forProject($project)->get('homepage')?->name());
        self::assertSame('app.mailer', $serviceIndexes->forProject($project)->get('app.mailer')?->id());
        self::assertSame('app.storage_dir', $parameterIndexes->forProject($project)->get('app.storage_dir')?->name());
        self::assertSame('project-php', $processRunner->command[0]);
        self::assertSame('--flag', $processRunner->command[1]);
        self::assertSame('--environment=test', $processRunner->command[4]);
        self::assertSame('--debug=1', $processRunner->command[5]);
        self::assertSame('--sections=routes,container', $processRunner->command[6]);
        self::assertSame($this->temporaryDirectory, $processRunner->workingDirectory);
        self::assertSame(90.0, $processRunner->timeout);
    }

    public function testMapsBridgeArgumentsToTheContainerProjectRoot(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $processRunner = new CapturingProcessRunner(new ProcessResult(0, json_encode([
            'schemaVersion' => 1,
            'sections' => [],
        ], \JSON_THROW_ON_ERROR), ''));
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $configuration = new RuntimeConfiguration();
        $configuration->configure([
            'phpCommand' => ['docker', 'compose', 'exec', '-T', 'php', 'php'],
            'containerProjectRoot' => '/app',
        ]);
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test', new Filesystem()),
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([]),
            $configuration,
            new ContainerPathMapper($configuration),
        );

        $initializer->initialize($project);

        self::assertSame(['docker', 'compose', 'exec', '-T', 'php', 'php'], \array_slice($processRunner->command, 0, 6));
        self::assertStringStartsWith('/app/var/symfony-lsp/test/', $processRunner->command[6]);
        self::assertStringEndsWith('/bridge.php', $processRunner->command[6]);
        self::assertSame('--project=/app', $processRunner->command[7]);
        self::assertSame($this->temporaryDirectory, $processRunner->workingDirectory);
        self::assertFileExists($this->temporaryDirectory.substr($processRunner->command[6], \strlen('/app')));
    }

    public function testRejectsRuntimeIndexingWithoutDebugMode(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $configuration = new RuntimeConfiguration();
        $configuration->configure(['debug' => false]);
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test', new Filesystem()),
            new CapturingProcessRunner(new ProcessResult(0, '', '')),
            new RuntimeSnapshotLoaderRegistry([]),
            $configuration,
            new ContainerPathMapper($configuration),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Runtime indexing requires Symfony debug mode.');

        $initializer->initialize(new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0'));
    }

    public function testRebuildsTheDebugContainerWhenRequiredByThePlan(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $processRunner = new CapturingProcessRunner(
            new ProcessResult(0, json_encode(['schemaVersion' => 1, 'sections' => []], \JSON_THROW_ON_ERROR), ''),
        );
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test', new Filesystem()),
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([]),
            new RuntimeConfiguration(),
            new ContainerPathMapper(new RuntimeConfiguration()),
        );

        $initializer->initialize(
            new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0'),
            new RuntimeRefreshPlan(RuntimeRefreshMode::Clear),
        );

        self::assertCount(1, $processRunner->commands);
        self::assertContains('--debug=1', $processRunner->commands[0]);
        self::assertContains('--rebuild-container=1', $processRunner->commands[0]);
        self::assertSame(300.0, $processRunner->timeout);
    }

    public function testRefreshesOnlyPlannedSectionsAgainstTheExistingContainer(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $processRunner = new CapturingProcessRunner(
            new ProcessResult(0, json_encode(['schemaVersion' => 1, 'sections' => []], \JSON_THROW_ON_ERROR), ''),
        );
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test', new Filesystem()),
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([]),
            new RuntimeConfiguration(),
            new ContainerPathMapper(new RuntimeConfiguration()),
        );

        $initializer->initialize(
            new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0'),
            new RuntimeRefreshPlan(RuntimeRefreshMode::Reuse, ['routes'], true),
        );

        self::assertCount(1, $processRunner->commands);
        self::assertContains('--sections=routes', $processRunner->commands[0]);
        self::assertContains('--targeted-refresh=1', $processRunner->commands[0]);
    }

    public function testLoadsAvailableSectionsBeforeReportingSectionErrors(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $serviceIndexes = new ServiceIndexRegistry();
        $routeIndexes = new RouteIndexRegistry();
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test', new Filesystem()),
            new CapturingProcessRunner(new ProcessResult(0, json_encode([
                'schemaVersion' => 1,
                'errors' => [['section' => 'routes', 'message' => 'CANARY_RUNTIME_SECTION_ERROR']],
                'sections' => [
                    'routes' => ['complete' => true, 'items' => [['name' => 'replacement', 'path' => '/replacement']]],
                    'container' => ['complete' => true, 'items' => [
                        ['id' => 'app.mailer', 'class' => 'App\\Mailer'],
                    ], 'parameters' => []],
                ],
            ], \JSON_THROW_ON_ERROR), '')),
            new RuntimeSnapshotLoaderRegistry([
                new ProjectRouteSnapshotLoader($routeIndexes),
                new ProjectServiceSnapshotLoader($serviceIndexes, new ParameterIndexRegistry()),
            ]),
            new RuntimeConfiguration(),
            new ContainerPathMapper(new RuntimeConfiguration()),
        );

        $routeIndexes->forProject($project)->replace(new Route('existing', '/existing', [], [], null, null));
        try {
            $initializer->initialize($project);
            self::fail('The section error was not reported.');
        } catch (\RuntimeException $error) {
            self::assertSame('The project bridge could not load runtime metadata: routes.', $error->getMessage());
        }
        self::assertSame('app.mailer', $serviceIndexes->forProject($project)->get('app.mailer')?->id());
        self::assertSame('existing', $routeIndexes->forProject($project)->get('existing')?->name());
        self::assertNull($routeIndexes->forProject($project)->get('replacement'));
    }

    public function testRejectsFailedBridgeExecutionWithoutExposingErrorOutput(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test', new Filesystem()),
            new CapturingProcessRunner(new ProcessResult(1, '', "CANARY_SECRET_RUNTIME_OUTPUT\n")),
            new RuntimeSnapshotLoaderRegistry([
                new ProjectRouteSnapshotLoader(new RouteIndexRegistry()),
            ]),
            new RuntimeConfiguration(),
            new ContainerPathMapper(new RuntimeConfiguration()),
        );

        try {
            $initializer->initialize(new Project(
                $this->temporaryDirectory,
                'file://'.$this->temporaryDirectory,
                '^8.0',
            ));
            self::fail('The failed bridge execution was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('The project bridge failed with status 1.', $error->getMessage());
        }
    }

    public function testLoadsTheSnapshotWhenStrayOutputSurroundsThePayload(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $payload = json_encode([
            'schemaVersion' => 1,
            'sections' => [
                'routes' => ['complete' => true, 'items' => [['name' => 'homepage', 'path' => '/']]],
            ],
        ], \JSON_THROW_ON_ERROR);
        $indexes = new RouteIndexRegistry();
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test', new Filesystem()),
            new CapturingProcessRunner(new ProcessResult(
                0,
                "Deprecated: something is deprecated in vendor/lib.php on line 1\n".$payload."\nstray shutdown output\n",
                '',
            )),
            new RuntimeSnapshotLoaderRegistry([new ProjectRouteSnapshotLoader($indexes)]),
            new RuntimeConfiguration(),
            new ContainerPathMapper(new RuntimeConfiguration()),
        );

        $initializer->initialize($project);

        self::assertSame('homepage', $indexes->forProject($project)->get('homepage')?->name());
    }

    public function testRejectsMissingPayloadWithoutExposingErrorOutput(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test', new Filesystem()),
            new CapturingProcessRunner(new ProcessResult(
                0,
                "Deprecated: something is deprecated in vendor/lib.php on line 1\n",
                "CANARY_SECRET_RUNTIME_OUTPUT\n",
            )),
            new RuntimeSnapshotLoaderRegistry([]),
            new RuntimeConfiguration(),
            new ContainerPathMapper(new RuntimeConfiguration()),
        );

        try {
            $initializer->initialize(new Project(
                $this->temporaryDirectory,
                'file://'.$this->temporaryDirectory,
                '^8.0',
            ));
            self::fail('The missing bridge payload was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('The project bridge returned invalid JSON.', $error->getMessage());
        }
    }
}

final class CapturingProcessRunner implements ProcessRunnerInterface
{
    /** @var non-empty-list<string> */
    public array $command = ['unset'];

    /** @var list<non-empty-list<string>> */
    public array $commands = [];
    public string $workingDirectory = '';
    public ?float $timeout = null;

    /** @var list<ProcessResult> */
    private array $results;

    public function __construct(ProcessResult ...$results)
    {
        $this->results = array_values($results);
    }

    public function run(array $command, string $workingDirectory, ?Cancellation $cancellation = null, ?float $timeout = null): ProcessResult
    {
        $this->command = $command;
        $this->commands[] = $command;
        $this->workingDirectory = $workingDirectory;
        $this->timeout = $timeout;

        return array_shift($this->results) ?? throw new \LogicException('No process result was configured.');
    }
}
