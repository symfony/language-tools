<?php

namespace Symfony\Lsp\Tests\Runtime;

use Amp\Cancellation;
use Amp\CancelledException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Feature\Configuration\ConfigurationValidationException;
use Symfony\Lsp\Feature\Configuration\ConfigurationValidationRegistry;
use Symfony\Lsp\Feature\Configuration\ConfigurationValidationResult;
use Symfony\Lsp\Feature\Configuration\ProjectConfigurationValidationSnapshotLoader;
use Symfony\Lsp\Feature\DependencyInjection\ParameterIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\ProjectServiceSnapshotLoader;
use Symfony\Lsp\Feature\DependencyInjection\ServiceIndexRegistry;
use Symfony\Lsp\Feature\Route\ProjectRouteSnapshotLoader;
use Symfony\Lsp\Feature\Route\Route;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Runtime\BridgeExecutionException;
use Symfony\Lsp\Runtime\BridgeInstaller;
use Symfony\Lsp\Runtime\PartialRuntimeMetadataException;
use Symfony\Lsp\Runtime\ProcessResult;
use Symfony\Lsp\Runtime\ProcessRunnerInterface;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeRefreshMode;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderRegistry;
use Symfony\Lsp\Runtime\RuntimeSnapshotState;
use Symfony\Lsp\Runtime\RuntimeSnapshotStore;
use Symfony\Lsp\Runtime\StatusRuntimeInitializer;
use Symfony\Lsp\Runtime\UnsupportedSymfonyVersionException;
use Symfony\Lsp\Server\SensitiveDataRedactor;
use Symfony\Lsp\Server\ServerLogger;
use Symfony\Lsp\Tests\Support\Bridge\ProjectRuntimeInitializerFixtureBuilder;

final class ProjectRuntimeInitializerTest extends TestCase
{
    private string $temporaryDirectory;

    private static function projects(Project ...$projects): ProjectRegistry
    {
        $registry = new ProjectRegistry();
        $registry->replace(array_values($projects));

        return $registry;
    }

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
            'timings' => [
                'bootstrapMilliseconds' => 1.0,
                'kernelMilliseconds' => 2.0,
                'sectionsMilliseconds' => ['routes' => 3.0, 'container' => 4.0, 'unknown' => 5.0],
                'shutdownMilliseconds' => 6.0,
                'totalMilliseconds' => 16.0,
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
        $statuses = new ProjectIndexStatusRegistry();
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([
                new ProjectRouteSnapshotLoader($indexes),
                new ProjectServiceSnapshotLoader($serviceIndexes, $parameterIndexes),
            ]),
            self::projects($project),
            configuration: $configuration,
            statuses: $statuses,
            releaseMetadataUrl: 'https://symfony.com/releases.json',
        );

        $initializer->initialize($project);

        self::assertSame('homepage', $indexes->forProject($project)->get('homepage')?->name);
        self::assertSame('app.mailer', $serviceIndexes->forProject($project)->get('app.mailer')?->id);
        self::assertSame('app.storage_dir', $parameterIndexes->forProject($project)->get('app.storage_dir')?->name);
        self::assertSame('project-php', $processRunner->command[0]);
        self::assertSame('--flag', $processRunner->command[1]);
        self::assertSame('--environment=test', $processRunner->command[4]);
        self::assertSame('--debug=1', $processRunner->command[5]);
        self::assertSame('--sections=routes,container', $processRunner->command[6]);
        self::assertSame('--configuration-generation=0', $processRunner->command[7]);
        self::assertSame('--release-metadata-url=https://symfony.com/releases.json', $processRunner->command[8]);
        self::assertMatchesRegularExpression('{^--release-metadata-cache=.+/var/symfony-lsp/test/[a-f0-9]{64}/release-metadata\.json$}', $processRunner->command[9]);
        self::assertNotContains('--error-details=1', $processRunner->command);
        self::assertSame($this->temporaryDirectory, $processRunner->workingDirectory);
        self::assertSame(90.0, $processRunner->timeout);
        self::assertSame([
            'scope' => 'full',
            'bootstrapMilliseconds' => 1.0,
            'kernelMilliseconds' => 2.0,
            'sectionsMilliseconds' => ['routes' => 3.0, 'container' => 4.0],
            'shutdownMilliseconds' => 6.0,
            'totalMilliseconds' => 16.0,
        ], $statuses->status($project)['runtime']['timings'] ?? null);
    }

    public function testCanDisableReleaseMetadataAccess(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $processRunner = new CapturingProcessRunner(new ProcessResult(0, json_encode([
            'schemaVersion' => 1,
            'sections' => [],
        ], \JSON_THROW_ON_ERROR), ''));
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $configuration = new RuntimeConfiguration();
        $configuration->configure(['releaseMetadata' => false]);
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([]),
            self::projects($project),
            configuration: $configuration,
            releaseMetadataUrl: 'https://symfony.com/releases.json',
        );

        $initializer->initialize($project);

        self::assertSame([], array_values(array_filter(
            $processRunner->command,
            static fn (string $argument): bool => str_starts_with($argument, '--release-metadata-'),
        )));
    }

    public function testRequestsBridgeErrorDetailsInVerboseMode(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $processRunner = new CapturingProcessRunner(new ProcessResult(0, json_encode([
            'schemaVersion' => 1,
            'sections' => [],
        ], \JSON_THROW_ON_ERROR), ''));
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $logger = new ServerLogger(null, new SensitiveDataRedactor());
        $logger->configure('verbose');
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([]),
            self::projects($project),
            logger: $logger,
        );

        $initializer->initialize($project);

        self::assertContains('--error-details=1', $processRunner->command);
    }

    public function testNeverLoadsMetadataForAProjectRemovedWhileTheBridgeRan(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $registry = self::projects($project);
        $processRunner = new RemovingProcessRunner($registry, new ProcessResult(0, json_encode([
            'schemaVersion' => 1,
            'sections' => [
                'routes' => [
                    'complete' => true,
                    'items' => [
                        ['name' => 'homepage', 'path' => '/'],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR), ''));
        $indexes = new RouteIndexRegistry();
        $configuration = new RuntimeConfiguration();
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([new ProjectRouteSnapshotLoader($indexes)]),
            $registry,
            configuration: $configuration,
        );

        try {
            $initializer->initialize($project);
            self::fail('Initialization for the removed project should have been abandoned.');
        } catch (CancelledException) {
        }

        self::assertNull($indexes->forProject($project)->get('homepage'));
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
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([]),
            self::projects($project),
            configuration: $configuration,
            releaseMetadataUrl: 'https://symfony.com/releases.json',
        );

        $initializer->initialize($project);

        self::assertSame(['docker', 'compose', 'exec', '-T', 'php', 'php'], \array_slice($processRunner->command, 0, 6));
        self::assertStringStartsWith('/app/var/symfony-lsp/test/', $processRunner->command[6]);
        self::assertStringEndsWith('/bridge.php', $processRunner->command[6]);
        self::assertSame('--project=/app', $processRunner->command[7]);
        self::assertSame('--release-metadata-url=https://symfony.com/releases.json', $processRunner->command[12]);
        self::assertMatchesRegularExpression('{^--release-metadata-cache=/app/var/symfony-lsp/test/[a-f0-9]{64}/release-metadata\.json$}', $processRunner->command[13]);
        self::assertSame($this->temporaryDirectory, $processRunner->workingDirectory);
        self::assertFileExists($this->temporaryDirectory.substr($processRunner->command[6], \strlen('/app')));
    }

    public function testRejectsRuntimeIndexingWithoutDebugMode(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $configuration = new RuntimeConfiguration();
        $configuration->configure(['debug' => false]);
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
            new CapturingProcessRunner(new ProcessResult(0, '', '')),
            new RuntimeSnapshotLoaderRegistry([]),
            self::projects($project),
            configuration: $configuration,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Runtime indexing requires Symfony debug mode.');

        $initializer->initialize($project);
    }

    public function testReportsSymfonyBranchesRejectedByReleaseMetadata(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^5.4');
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
            new CapturingProcessRunner(new ProcessResult(0, json_encode([
                'schemaVersion' => 1,
                'project' => ['symfonyBranch' => '5.4'],
                'unsupportedSymfonyVersion' => true,
            ], \JSON_THROW_ON_ERROR), '')),
            new RuntimeSnapshotLoaderRegistry([]),
            self::projects($project),
            configuration: new RuntimeConfiguration(),
        );

        $this->expectException(UnsupportedSymfonyVersionException::class);
        $this->expectExceptionMessage('Symfony 5.4 is not supported by Symfony Language Tools.');

        $initializer->initialize($project);
    }

    public function testAcceptsIntermediateSymfonyBranchesWithoutAnUnsupportedMarker(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $processRunner = new CapturingProcessRunner(new ProcessResult(0, json_encode([
            'schemaVersion' => 1,
            'project' => ['symfonyBranch' => '8.0'],
            'sections' => [],
        ], \JSON_THROW_ON_ERROR), ''));
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([]),
            self::projects($project),
            configuration: new RuntimeConfiguration(),
        );

        $initializer->initialize($project);

        self::assertCount(1, $processRunner->commands);
    }

    public function testRebuildsTheDebugContainerWhenRequiredByThePlan(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $processRunner = new CapturingProcessRunner(
            new ProcessResult(0, json_encode(['schemaVersion' => 1, 'sections' => []], \JSON_THROW_ON_ERROR), ''),
        );
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([]),
            self::projects($project),
            configuration: new RuntimeConfiguration(),
        );

        $initializer->initialize(
            $project,
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
            new ProcessResult(0, json_encode([
                'schemaVersion' => 1,
                'sections' => [],
                'timings' => [
                    'bootstrapMilliseconds' => 1.0,
                    'kernelMilliseconds' => 2.0,
                    'sectionsMilliseconds' => ['routes' => 3.0, 'container' => 4.0],
                    'shutdownMilliseconds' => 5.0,
                    'totalMilliseconds' => 11.0,
                ],
            ], \JSON_THROW_ON_ERROR), ''),
        );
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $statuses = new ProjectIndexStatusRegistry();
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([]),
            self::projects($project),
            configuration: new RuntimeConfiguration(),
            statuses: $statuses,
        );

        $initializer->initialize(
            $project,
            new RuntimeRefreshPlan(RuntimeRefreshMode::Reuse, ['routes'], true),
        );

        self::assertCount(1, $processRunner->commands);
        self::assertContains('--sections=routes', $processRunner->commands[0]);
        self::assertContains('--targeted-refresh=1', $processRunner->commands[0]);
        self::assertSame([
            'scope' => 'targeted',
            'bootstrapMilliseconds' => 1.0,
            'kernelMilliseconds' => 2.0,
            'sectionsMilliseconds' => ['routes' => 3.0],
            'shutdownMilliseconds' => 5.0,
            'totalMilliseconds' => 11.0,
        ], $statuses->status($project)['runtime']['timings'] ?? null);
    }

    public function testLoadsAvailableSectionsBeforeReportingSectionErrors(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $serviceIndexes = new ServiceIndexRegistry();
        $routeIndexes = new RouteIndexRegistry();
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
            new CapturingProcessRunner(new ProcessResult(0, json_encode([
                'schemaVersion' => 1,
                'project' => ['environment' => 'dev'],
                'configurationValidation' => ['status' => 'valid'],
                'errors' => [[
                    'section' => 'routes',
                    'message' => 'CANARY_RUNTIME_SECTION_ERROR',
                    'cause' => ['chain' => [[
                        'class' => \RuntimeException::class,
                        'message' => 'CANARY_RUNTIME_CAUSE',
                        'origin' => 'src/RuntimeExtension.php:42',
                        'frames' => ['App\\RuntimeExtension->load (src/RuntimeExtension.php:40)'],
                    ]]],
                ]],
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
            self::projects($project),
            configuration: new RuntimeConfiguration(),
        );

        $routeIndexes->forProject($project)->replace(new Route('existing', '/existing', [], [], null, null));
        try {
            $initializer->initialize($project);
            self::fail('The section error was not reported.');
        } catch (PartialRuntimeMetadataException $error) {
            self::assertSame(['routes'], $error->sections);
            self::assertSame('The project bridge could not load runtime metadata: routes.', $error->getMessage());
            self::assertSame([
                'Runtime section "routes": RuntimeException at src/RuntimeExtension.php:42: CANARY_RUNTIME_CAUSE',
                '  at App\\RuntimeExtension->load (src/RuntimeExtension.php:40)',
            ], $error->detailLines());
        }
        self::assertSame('app.mailer', $serviceIndexes->forProject($project)->get('app.mailer')?->id);
        self::assertSame('existing', $routeIndexes->forProject($project)->get('existing')?->name);
        self::assertNull($routeIndexes->forProject($project)->get('replacement'));
    }

    public function testRestoresOnlyFailedSectionsAfterAPartialBridgeError(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $projects = self::projects($project);
        $configuration = new RuntimeConfiguration();
        $bridgeInstaller = new BridgeInstaller($source, 'test', new Filesystem());
        $store = new RuntimeSnapshotStore($configuration, new Filesystem());
        $store->save($project, $bridgeInstaller->install($project), [
            'schemaVersion' => 1,
            'sections' => [
                'routes' => ['complete' => true, 'items' => [['name' => 'old_route', 'path' => '/old']]],
                'container' => [
                    'complete' => true,
                    'items' => [['id' => 'old.service', 'class' => 'App\\OldService']],
                    'parameters' => [],
                ],
            ],
        ], ['routes', 'container'], true);
        $routeIndexes = new RouteIndexRegistry();
        $serviceIndexes = new ServiceIndexRegistry();
        $state = new RuntimeSnapshotState();
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source, $bridgeInstaller))->build(
            new CapturingProcessRunner(new ProcessResult(0, json_encode([
                'schemaVersion' => 1,
                'project' => ['environment' => 'dev'],
                'configurationValidation' => ['status' => 'valid'],
                'errors' => [['section' => 'container', 'message' => 'CANARY_RUNTIME_SECTION_ERROR']],
                'sections' => [
                    'routes' => ['complete' => true, 'items' => [['name' => 'new_route', 'path' => '/new']]],
                    'container' => [
                        'complete' => true,
                        'items' => [['id' => 'new.service', 'class' => 'App\\NewService']],
                        'parameters' => [],
                    ],
                ],
            ], \JSON_THROW_ON_ERROR), '')),
            new RuntimeSnapshotLoaderRegistry([
                new ProjectRouteSnapshotLoader($routeIndexes),
                new ProjectServiceSnapshotLoader($serviceIndexes, new ParameterIndexRegistry()),
            ]),
            $projects,
            configuration: $configuration,
            snapshotStore: $store,
            snapshotState: $state,
        );

        try {
            $initializer->initialize($project);
            self::fail('The section error was not reported.');
        } catch (PartialRuntimeMetadataException $error) {
            self::assertSame(['container'], $error->sections);
            self::assertSame('The project bridge could not load runtime metadata: container.', $error->getMessage());
        }

        self::assertSame('new_route', $routeIndexes->forProject($project)->get('new_route')?->name);
        self::assertNull($routeIndexes->forProject($project)->get('old_route'));
        self::assertSame('old.service', $serviceIndexes->forProject($project)->get('old.service')?->id);
        self::assertNull($serviceIndexes->forProject($project)->get('new.service'));
        self::assertTrue($state->has($project));
        self::assertSame([
            'routes' => ['complete' => true, 'items' => [['name' => 'new_route', 'path' => '/new']]],
            'container' => [
                'complete' => true,
                'items' => [['id' => 'old.service', 'class' => 'App\\OldService']],
                'parameters' => [],
            ],
        ], $store->load($project, $bridgeInstaller->install($project))?->snapshot['sections'] ?? null);
    }

    public function testRejectsStaleConfigurationValidationResults(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $projects = self::projects($project);
        $configuration = new RuntimeConfiguration();
        $bridgeInstaller = new BridgeInstaller($source, 'test', new Filesystem());
        $store = new RuntimeSnapshotStore($configuration, new Filesystem());
        $store->save($project, $bridgeInstaller->install($project), [
            'schemaVersion' => 1,
            'sections' => ['routes' => ['complete' => true, 'items' => [['name' => 'persisted', 'path' => '/persisted']]]],
        ], ['routes'], true);
        $state = new RuntimeSnapshotState();
        $validations = new ConfigurationValidationRegistry();
        $validations->pending($project);
        $routeIndexes = new RouteIndexRegistry();
        $processRunner = new CapturingProcessRunner(new ProcessResult(0, json_encode([
            'schemaVersion' => 1,
            'configurationGeneration' => 0,
            'project' => ['environment' => 'dev'],
            'configurationValidation' => ['status' => 'valid'],
            'sections' => ['routes' => ['complete' => true, 'items' => [['name' => 'stale', 'path' => '/stale']]]],
        ], \JSON_THROW_ON_ERROR), ''));
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source, $bridgeInstaller))->build(
            $processRunner,
            new RuntimeSnapshotLoaderRegistry([new ProjectRouteSnapshotLoader($routeIndexes)]),
            $projects,
            configuration: $configuration,
            configurationValidationLoader: new ProjectConfigurationValidationSnapshotLoader($validations),
            snapshotStore: $store,
            snapshotState: $state,
        );

        try {
            $initializer->initialize($project);
            self::fail('The stale configuration validation was accepted.');
        } catch (CancelledException) {
        }

        self::assertContains('--configuration-generation=1', $processRunner->command);
        self::assertSame(ConfigurationValidationResult::PENDING, $validations->result($project)->state);
        self::assertNull($routeIndexes->forProject($project)->get('stale'));
        self::assertNull($routeIndexes->forProject($project)->get('persisted'));
        self::assertFalse($state->has($project));
    }

    public function testLoadsConfigurationValidationBeforeReportingRuntimeFailure(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $validations = new ConfigurationValidationRegistry();
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
            new CapturingProcessRunner(new ProcessResult(0, json_encode([
                'schemaVersion' => 1,
                'project' => ['environment' => 'dev'],
                'configurationValidation' => [
                    'status' => 'invalid',
                    'kind' => 'configuration',
                    'path' => 'framework.router',
                ],
                'sections' => [],
                'errors' => [['section' => 'runtime', 'message' => 'CANARY_RUNTIME_SECTION_ERROR']],
            ], \JSON_THROW_ON_ERROR), '')),
            new RuntimeSnapshotLoaderRegistry([]),
            self::projects($project),
            configuration: new RuntimeConfiguration(),
            configurationValidationLoader: new ProjectConfigurationValidationSnapshotLoader($validations),
        );

        try {
            $initializer->initialize($project);
            self::fail('The configuration validation failure was not reported.');
        } catch (ConfigurationValidationException $error) {
            self::assertSame('framework.router', $error->validation->path);
        }
        self::assertSame(ConfigurationValidationResult::INVALID, $validations->result($project)->state);
    }

    public function testRestoresPersistedMetadataWhenTheInitialBridgeExecutionFails(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $projects = self::projects($project);
        $configuration = new RuntimeConfiguration();
        $firstIndexes = new RouteIndexRegistry();
        $firstState = new RuntimeSnapshotState();
        $firstStatuses = new ProjectIndexStatusRegistry($firstState);
        $first = new StatusRuntimeInitializer(
            (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
                new CapturingProcessRunner(new ProcessResult(0, json_encode([
                    'schemaVersion' => 1,
                    'sections' => [
                        'routes' => ['complete' => true, 'items' => [['name' => 'homepage', 'path' => '/']]],
                    ],
                ], \JSON_THROW_ON_ERROR), '')),
                new RuntimeSnapshotLoaderRegistry([new ProjectRouteSnapshotLoader($firstIndexes)]),
                $projects,
                configuration: $configuration,
                snapshotStore: new RuntimeSnapshotStore($configuration, new Filesystem()),
                snapshotState: $firstState,
            ),
            $firstStatuses,
            $projects,
        );
        $first->initialize($project);
        self::assertSame('ready', $firstStatuses->status($project)['runtime']['state']);

        $restoredIndexes = new RouteIndexRegistry();
        $restoredState = new RuntimeSnapshotState();
        $restoredStatuses = new ProjectIndexStatusRegistry($restoredState);
        $restored = new StatusRuntimeInitializer(
            (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
                new CapturingProcessRunner(new ProcessResult(1, '', '')),
                new RuntimeSnapshotLoaderRegistry([new ProjectRouteSnapshotLoader($restoredIndexes)]),
                $projects,
                configuration: $configuration,
                snapshotStore: new RuntimeSnapshotStore($configuration, new Filesystem()),
                snapshotState: $restoredState,
            ),
            $restoredStatuses,
            $projects,
        );

        try {
            $restored->initialize($project);
            self::fail('The failed bridge execution was accepted.');
        } catch (BridgeExecutionException) {
        }

        self::assertSame('homepage', $restoredIndexes->forProject($project)->get('homepage')?->name);
        $status = $restoredStatuses->status($project)['runtime'];
        self::assertSame('stale', $status['state']);
        self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\+00:00$/D', $status['lastSuccessfulAt'] ?? '');
        self::assertSame('The application failed to boot.', $status['error'] ?? null);
        self::assertSame('bootstrap', $status['stage'] ?? null);
    }

    public function testKeepsTheActiveSnapshotWhenARefreshFails(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $projects = self::projects($project);
        $configuration = new RuntimeConfiguration();
        $bridgeInstaller = new BridgeInstaller($source, 'test', new Filesystem());
        $store = new RuntimeSnapshotStore($configuration, new Filesystem());
        $store->save($project, $bridgeInstaller->install($project), [
            'schemaVersion' => 1,
            'sections' => [
                'routes' => ['complete' => true, 'items' => [['name' => 'persisted', 'path' => '/persisted']]],
            ],
        ], ['routes'], true);
        $indexes = new RouteIndexRegistry();
        $indexes->forProject($project)->replace(new Route('current', '/current', [], [], null, null));
        $state = new RuntimeSnapshotState();
        $state->markReady($project);
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source, $bridgeInstaller))->build(
            new CapturingProcessRunner(new ProcessResult(1, '', '')),
            new RuntimeSnapshotLoaderRegistry([new ProjectRouteSnapshotLoader($indexes)]),
            $projects,
            configuration: $configuration,
            snapshotStore: $store,
            snapshotState: $state,
        );

        try {
            $initializer->initialize($project);
            self::fail('The failed bridge execution was accepted.');
        } catch (BridgeExecutionException) {
        }

        self::assertSame('current', $indexes->forProject($project)->get('current')?->name);
        self::assertNull($indexes->forProject($project)->get('persisted'));
    }

    public function testKeepsCurrentConfigurationFailureWithRestoredMetadata(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $projects = self::projects($project);
        $configuration = new RuntimeConfiguration();
        $bridgeInstaller = new BridgeInstaller($source, 'test', new Filesystem());
        $store = new RuntimeSnapshotStore($configuration, new Filesystem());
        $store->save($project, $bridgeInstaller->install($project), [
            'schemaVersion' => 1,
            'sections' => [
                'routes' => ['complete' => true, 'items' => [['name' => 'homepage', 'path' => '/']]],
            ],
        ], ['routes'], true);
        $indexes = new RouteIndexRegistry();
        $validations = new ConfigurationValidationRegistry();
        $state = new RuntimeSnapshotState();
        $statuses = new ProjectIndexStatusRegistry($state);
        $initializer = new StatusRuntimeInitializer(
            (new ProjectRuntimeInitializerFixtureBuilder($source, $bridgeInstaller))->build(
                new CapturingProcessRunner(new ProcessResult(0, json_encode([
                    'schemaVersion' => 1,
                    'project' => ['environment' => 'dev'],
                    'configurationValidation' => [
                        'status' => 'invalid',
                        'kind' => 'configuration',
                        'path' => 'framework.router',
                    ],
                    'sections' => [],
                    'errors' => [['section' => 'runtime', 'message' => 'CANARY_RUNTIME_SECTION_ERROR']],
                ], \JSON_THROW_ON_ERROR), '')),
                new RuntimeSnapshotLoaderRegistry([new ProjectRouteSnapshotLoader($indexes)]),
                $projects,
                configuration: $configuration,
                configurationValidationLoader: new ProjectConfigurationValidationSnapshotLoader($validations),
                snapshotStore: $store,
                snapshotState: $state,
            ),
            $statuses,
            $projects,
        );

        try {
            $initializer->initialize($project);
            self::fail('The configuration validation failure was not reported.');
        } catch (ConfigurationValidationException $error) {
            self::assertSame('framework.router', $error->validation->path);
        }

        self::assertSame(ConfigurationValidationResult::INVALID, $validations->result($project)->state);
        self::assertSame('homepage', $indexes->forProject($project)->get('homepage')?->name);
        self::assertSame('stale', $statuses->status($project)['runtime']['state']);
        self::assertSame('configuration', $statuses->status($project)['runtime']['stage'] ?? null);
    }

    public function testRejectsFailedBridgeExecutionWithoutExposingErrorOutput(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
            new CapturingProcessRunner(new ProcessResult(1, '', "CANARY_SECRET_RUNTIME_OUTPUT\n")),
            new RuntimeSnapshotLoaderRegistry([
                new ProjectRouteSnapshotLoader(new RouteIndexRegistry()),
            ]),
            self::projects($project),
            configuration: new RuntimeConfiguration(),
        );

        try {
            $initializer->initialize($project);
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
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
            new CapturingProcessRunner(new ProcessResult(
                0,
                "Deprecated: something is deprecated in vendor/lib.php on line 1\n".$payload."\nstray shutdown output\n",
                '',
            )),
            new RuntimeSnapshotLoaderRegistry([new ProjectRouteSnapshotLoader($indexes)]),
            self::projects($project),
            configuration: new RuntimeConfiguration(),
        );

        $initializer->initialize($project);

        self::assertSame('homepage', $indexes->forProject($project)->get('homepage')?->name);
    }

    public function testRejectsMissingPayloadWithoutExposingErrorOutput(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php');
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');
        $initializer = (new ProjectRuntimeInitializerFixtureBuilder($source))->build(
            new CapturingProcessRunner(new ProcessResult(
                0,
                "Deprecated: something is deprecated in vendor/lib.php on line 1\n",
                "CANARY_SECRET_RUNTIME_OUTPUT\n",
            )),
            new RuntimeSnapshotLoaderRegistry([]),
            self::projects($project),
            configuration: new RuntimeConfiguration(),
        );

        try {
            $initializer->initialize($project);
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

final class RemovingProcessRunner implements ProcessRunnerInterface
{
    /** @var list<ProcessResult> */
    private array $results;

    public function __construct(
        private readonly ProjectRegistry $registry,
        ProcessResult ...$results,
    ) {
        $this->results = array_values($results);
    }

    public function run(array $command, string $workingDirectory, ?Cancellation $cancellation = null, ?float $timeout = null): ProcessResult
    {
        $this->registry->replace([]);

        return array_shift($this->results) ?? throw new \LogicException('No process result was configured.');
    }
}
