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
            'debug' => false,
        ]);
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test', new Filesystem()),
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([
                new ProjectRouteSnapshotLoader($indexes),
                new ProjectServiceSnapshotLoader($serviceIndexes, $parameterIndexes),
            ]),
            $configuration,
        );

        $initializer->initialize($project);

        self::assertSame('homepage', $indexes->forProject($project)->get('homepage')?->name());
        self::assertSame('app.mailer', $serviceIndexes->forProject($project)->get('app.mailer')?->id());
        self::assertSame('app.storage_dir', $parameterIndexes->forProject($project)->get('app.storage_dir')?->name());
        self::assertSame('project-php', $processRunner->command[0]);
        self::assertSame('--flag', $processRunner->command[1]);
        self::assertSame('--environment=test', $processRunner->command[4]);
        self::assertSame('--debug=0', $processRunner->command[5]);
        self::assertSame('--sections=routes,container', $processRunner->command[6]);
        self::assertSame($this->temporaryDirectory, $processRunner->workingDirectory);
    }

    public function testRebuildsTheNormalNonDebugProjectCacheWhileRefreshingMetadata(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $processRunner = new CapturingProcessRunner(
            new ProcessResult(0, json_encode(['schemaVersion' => 1, 'sections' => []], \JSON_THROW_ON_ERROR), ''),
        );
        $configuration = new RuntimeConfiguration();
        $configuration->configure([
            'phpCommand' => ['project-php'],
            'consolePath' => 'app-console',
            'environment' => 'test',
            'debug' => false,
        ]);
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test', new Filesystem()),
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([]),
            $configuration,
        );

        $initializer->initialize(
            new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0'),
            new RuntimeRefreshPlan(RuntimeRefreshMode::Clear),
        );

        self::assertCount(1, $processRunner->commands);
        self::assertStringEndsWith('/bridge.php', $processRunner->commands[0][1]);
        self::assertContains('--debug=0', $processRunner->commands[0]);
        self::assertContains('--rebuild-container=1', $processRunner->commands[0]);
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
        );

        $initializer->initialize(
            new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0'),
            new RuntimeRefreshPlan(RuntimeRefreshMode::Clear),
        );

        self::assertCount(1, $processRunner->commands);
        self::assertContains('--debug=1', $processRunner->commands[0]);
        self::assertContains('--rebuild-container=1', $processRunner->commands[0]);
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
        );

        $initializer->initialize(
            new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0'),
            new RuntimeRefreshPlan(RuntimeRefreshMode::Reuse, ['routes'], true),
        );

        self::assertCount(1, $processRunner->commands);
        self::assertContains('--sections=routes', $processRunner->commands[0]);
        self::assertContains('--targeted-refresh=1', $processRunner->commands[0]);
    }

    public function testFallsBackToAFullTargetedRefreshWithoutDebugResourceChecks(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $processRunner = new CapturingProcessRunner(
            new ProcessResult(0, json_encode(['schemaVersion' => 1, 'sections' => []], \JSON_THROW_ON_ERROR), ''),
        );
        $configuration = new RuntimeConfiguration();
        $configuration->configure(['debug' => false]);
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test', new Filesystem()),
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([]),
            $configuration,
        );

        $initializer->initialize(
            new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0'),
            new RuntimeRefreshPlan(RuntimeRefreshMode::Reuse, ['routes'], true),
        );

        self::assertContains('--rebuild-container=1', $processRunner->commands[0]);
        self::assertNotContains('--targeted-refresh=1', $processRunner->commands[0]);
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
                'errors' => [['section' => 'routes', 'message' => 'missing environment']],
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
        );

        $routeIndexes->forProject($project)->replace(new Route('existing', '/existing', [], [], null, null));
        try {
            $initializer->initialize($project);
            self::fail('The section error was not reported.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('missing environment', $error->getMessage());
        }
        self::assertSame('app.mailer', $serviceIndexes->forProject($project)->get('app.mailer')?->id());
        self::assertSame('existing', $routeIndexes->forProject($project)->get('existing')?->name());
        self::assertNull($routeIndexes->forProject($project)->get('replacement'));
    }

    public function testRejectsFailedBridgeExecution(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $initializer = new ProjectRuntimeInitializer(
            new BridgeInstaller($source, 'test', new Filesystem()),
            new CapturingProcessRunner(new ProcessResult(1, '', 'broken container')),
            new RuntimeSnapshotLoaderRegistry([
                new ProjectRouteSnapshotLoader(new RouteIndexRegistry()),
            ]),
            new RuntimeConfiguration(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('broken container');

        $initializer->initialize(new Project(
            $this->temporaryDirectory,
            'file://'.$this->temporaryDirectory,
            '^8.0',
        ));
    }
}

final class CapturingProcessRunner implements ProcessRunnerInterface
{
    /** @var non-empty-list<string> */
    public array $command = ['unset'];

    /** @var list<non-empty-list<string>> */
    public array $commands = [];
    public string $workingDirectory = '';

    /** @var list<ProcessResult> */
    private array $results;

    public function __construct(ProcessResult ...$results)
    {
        $this->results = array_values($results);
    }

    public function run(array $command, string $workingDirectory, ?Cancellation $cancellation = null): ProcessResult
    {
        $this->command = $command;
        $this->commands[] = $command;
        $this->workingDirectory = $workingDirectory;

        return array_shift($this->results) ?? throw new \LogicException('No process result was configured.');
    }
}
