<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\Bridge\AutoloaderFixtureBuilder;
use Symfony\Lsp\Tests\Support\Bridge\BridgeFixtureWorkspace;
use Symfony\Lsp\Tests\Support\Bridge\BridgeProcessFixture;
use Symfony\Lsp\Tests\Support\Bridge\RouteFixtureBuilder;

final class BridgeTest extends TestCase
{
    private BridgeFixtureWorkspace $workspace;
    private BridgeProcessFixture $bridge;

    protected function setUp(): void
    {
        $this->workspace = new BridgeFixtureWorkspace();
        $this->bridge = new BridgeProcessFixture($this->workspace->path);
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    #[DataProvider('versionProvider')]
    public function testReportsProjectMetadataWithoutAStaticBranchList(string $version): void
    {
        (new AutoloaderFixtureBuilder($this->workspace))->writeAutoloader($version);

        $process = $this->bridge->run(['--environment=test', '--debug=0']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertIsArray($result['project'] ?? null);
        self::assertSame($version, $result['project']['symfonyVersion']);
        self::assertSame('42.7', $result['project']['symfonyBranch']);
        self::assertSame('test', $result['project']['environment']);
        self::assertFalse($result['project']['debug']);
    }

    #[DataProvider('unsupportedBranchProvider')]
    public function testReportsBranchesOutsideTheReleaseMetadataRangeWithoutBootingTheApplication(string $version, string $branch): void
    {
        (new AutoloaderFixtureBuilder($this->workspace))->writeAutoloader($version);
        $metadata = $this->workspace->write('releases.json', json_encode([
            'supported_versions' => ['6.4', '7.4', '8.1'],
        ], \JSON_THROW_ON_ERROR));
        $cache = $this->workspace->path.'/release-metadata-cache.json';

        $process = $this->bridge->run([
            '--release-metadata-url='.$metadata,
            '--release-metadata-cache='.$cache,
        ]);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        self::assertIsArray($process->snapshot);
        self::assertTrue($process->snapshot['unsupportedSymfonyVersion'] ?? false);
        $project = $process->snapshot['project'] ?? null;
        self::assertIsArray($project);
        self::assertSame($branch, $project['symfonyBranch'] ?? null);
        self::assertArrayNotHasKey('configurationValidation', $process->snapshot);
        self::assertSame((string) file_get_contents($metadata), (string) file_get_contents($cache));
    }

    public function testAcceptsIntermediateBranchesWithinTheReleaseMetadataRange(): void
    {
        (new AutoloaderFixtureBuilder($this->workspace))->writeAutoloader('8.0.13');
        $metadata = $this->workspace->write('releases.json', json_encode([
            'supported_versions' => ['8.1', '6.4', '7.4'],
        ], \JSON_THROW_ON_ERROR));

        $process = $this->bridge->run([
            '--release-metadata-url='.$metadata,
            '--release-metadata-cache='.$this->workspace->path.'/release-metadata-cache.json',
        ]);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        self::assertIsArray($process->snapshot);
        self::assertArrayNotHasKey('unsupportedSymfonyVersion', $process->snapshot);
    }

    public function testUsesStaleReleaseMetadataWhenRefreshFails(): void
    {
        (new AutoloaderFixtureBuilder($this->workspace))->writeAutoloader('5.4.45');
        $cache = $this->workspace->write('release-metadata-cache.json', json_encode([
            'supported_versions' => ['6.4', '7.4', '8.1'],
        ], \JSON_THROW_ON_ERROR));
        touch($cache, time() - 7200);

        $process = $this->bridge->run([
            '--release-metadata-url='.$this->workspace->path.'/missing-releases.json',
            '--release-metadata-cache='.$cache,
        ]);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        self::assertIsArray($process->snapshot);
        self::assertTrue($process->snapshot['unsupportedSymfonyVersion'] ?? false);
    }

    public function testContinuesWhenReleaseMetadataIsInvalid(): void
    {
        (new AutoloaderFixtureBuilder($this->workspace))->writeAutoloader('5.4.45');
        $metadata = $this->workspace->write('releases.json', json_encode([
            'supported_versions' => ['invalid'],
        ], \JSON_THROW_ON_ERROR));

        $process = $this->bridge->run([
            '--release-metadata-url='.$metadata,
            '--release-metadata-cache='.$this->workspace->path.'/missing-cache.json',
        ]);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        self::assertIsArray($process->snapshot);
        self::assertArrayNotHasKey('unsupportedSymfonyVersion', $process->snapshot);
    }

    public function testApplicationGlobalFunctionsDoNotCollideWithBridgeSymbols(): void
    {
        (new AutoloaderFixtureBuilder($this->workspace))->writeApplicationGlobalFunctions();

        $process = $this->bridge->run(['--environment=test', '--debug=0']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame(1, $result['schemaVersion'] ?? null);
        self::assertIsArray($result['project'] ?? null);
        self::assertSame('42.7.3', $result['project']['symfonyVersion'] ?? null);
        self::assertSame([], $result['sections'] ?? null);
        self::assertSame([], $result['errors'] ?? null);
        $timings = $result['timings'] ?? null;
        self::assertIsArray($timings);
        self::assertSame([], $timings['sectionsMilliseconds'] ?? null);
        foreach (['bootstrapMilliseconds', 'kernelMilliseconds', 'shutdownMilliseconds', 'totalMilliseconds'] as $key) {
            self::assertTrue(\is_int($timings[$key] ?? null) || \is_float($timings[$key] ?? null));
            self::assertGreaterThanOrEqual(0.0, (float) $timings[$key]);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function versionProvider(): iterable
    {
        yield 'release' => ['42.7.3'];
        yield 'prefixed release' => ['v42.7.3'];
        yield 'prerelease' => ['42.7.0-RC1'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsupportedBranchProvider(): iterable
    {
        yield 'older' => ['5.4.45', '5.4'];
        yield 'newer' => ['8.2.0-BETA1', '8.2'];
    }

    public function testKeepsStrayProjectOutputOffTheStdoutPayload(): void
    {
        (new AutoloaderFixtureBuilder($this->workspace))->writeStrayOutputApplication();
        $process = $this->bridge->run(
            ['--environment=test', '--debug=0'],
            ['-d', 'display_errors=1', '-d', 'error_reporting=-1'],
        );

        $stdout = $process->stdout;
        $stderr = $process->stderr;
        self::assertSame(0, $process->exitCode, $stdout.$stderr);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertIsArray($result['project'] ?? null);
        self::assertSame('42.7.3', $result['project']['symfonyVersion']);
        self::assertStringContainsString('stray autoload output', $stderr);
        self::assertStringContainsString('Deprecated', $stderr);
    }

    public function testRebuildsContainerCacheBeforeLoadingSections(): void
    {
        (new RouteFixtureBuilder($this->workspace))->writeRouteApplication();
        $this->workspace->makeDirectory('var/cache');
        $this->workspace->write('var/cache/marker', 'stale');

        $process = $this->bridge->run(['--sections=routes', '--rebuild-container=1']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        self::assertFileDoesNotExist($this->workspace->path.'/var/cache/marker');
    }

    public function testTargetedRefreshDiscardsStaleTranslationCatalogueCaches(): void
    {
        (new RouteFixtureBuilder($this->workspace))->writeRouteApplication();
        $catalogue = $this->workspace->write('var/cache/translations/catalogue.en.stale.php', '<?php return [];');

        $process = $this->bridge->run(['--sections=translations', '--targeted-refresh=1']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        self::assertFileDoesNotExist($catalogue);
        $this->workspace->remove('var');
    }

    public function testInitialIndexKeepsTranslationCatalogueCaches(): void
    {
        (new RouteFixtureBuilder($this->workspace))->writeRouteApplication();
        $catalogue = $this->workspace->write('var/cache/translations/catalogue.en.stale.php', '<?php return [];');

        $process = $this->bridge->run(['--sections=translations']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        self::assertFileExists($catalogue);
        $this->workspace->remove('var');
    }

    public function testDoesNotExposeApplicationExceptionsInSnapshot(): void
    {
        (new RouteFixtureBuilder($this->workspace))->writeRouteApplication();
        self::assertSame(1, $this->workspace->replace(
            'vendor/autoload.php',
            'public function __construct(string $environment, bool $debug) {}',
            'public function __construct(string $environment, bool $debug) { throw new \\RuntimeException(\'CANARY_RUNTIME_EXCEPTION\'); }',
        ));

        $process = $this->bridge->run(['--sections=routes']);

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $process->stderr."\n".$snapshot);
        self::assertStringNotContainsString('CANARY_RUNTIME_EXCEPTION', $snapshot);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame(['status' => 'unavailable'], $result['configurationValidation'] ?? null);
        self::assertSame([[
            'section' => 'routes',
            'message' => 'Unable to load the "routes" runtime metadata section.',
        ]], $result['errors']);
    }

    public function testUsesOneKernelAndApplicationForAllRequestedSections(): void
    {
        (new RouteFixtureBuilder($this->workspace))->writeSharedKernelApplication();

        $process = $this->bridge->run(['--sections=routes,container,environment']);

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $process->stderr."\n".$snapshot);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertSame(['routes', 'container', 'environment'], array_keys($result['sections']));
    }

    public function testKeepsCollectedSectionsWhenKernelShutdownFails(): void
    {
        (new RouteFixtureBuilder($this->workspace))->writeRouteApplication();
        self::assertSame(1, $this->workspace->replace(
            'vendor/autoload.php',
            'public function shutdown(): void {}',
            'public function shutdown(): void { throw new \\RuntimeException(\'CANARY_SHUTDOWN_EXCEPTION\'); }',
        ));

        $process = $this->bridge->run(['--sections=routes']);

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $process->stderr."\n".$snapshot);
        self::assertStringNotContainsString('CANARY_SHUTDOWN_EXCEPTION', $snapshot);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        $sections = $result['sections'] ?? null;
        self::assertIsArray($sections);
        $routes = $sections['routes'] ?? null;
        self::assertIsArray($routes);
        $items = $routes['items'] ?? null;
        self::assertIsArray($items);
        $homepage = $items[2] ?? null;
        self::assertIsArray($homepage);
        self::assertSame('homepage', $homepage['name'] ?? null);
    }

    public function testDiscoversTheKernelFromComposerPsr4AutoloadRoots(): void
    {
        (new RouteFixtureBuilder($this->workspace))->writeRouteApplication('Acme');
        $this->workspace->write('composer.json', json_encode([
            'autoload' => ['psr-4' => ['Acme\\' => 'src/']],
        ], \JSON_THROW_ON_ERROR));
        $this->workspace->makeDirectory('src');
        $this->workspace->write('src/Kernel.php', '<?php');

        $process = $this->bridge->run(['--sections=routes']);

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $process->stderr."\n".$snapshot);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors']);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['routes'] ?? null);
        self::assertTrue($result['sections']['routes']['complete']);
    }

    public function testSkipsPsr4KernelCandidatesThatAreNotKernels(): void
    {
        (new RouteFixtureBuilder($this->workspace))->writeMultiRootKernelApplication();
        $this->workspace->write('composer.json', json_encode([
            'autoload' => ['psr-4' => ['Tests\\' => 'tests/', 'Acme\\' => 'src/']],
        ], \JSON_THROW_ON_ERROR));
        $this->workspace->makeDirectory('src');
        $this->workspace->makeDirectory('tests');
        $this->workspace->write('src/Kernel.php', '<?php');
        $this->workspace->write('tests/Kernel.php', '<?php');

        $process = $this->bridge->run(['--sections=routes']);

        $snapshot = $process->stdout;
        self::assertSame(0, $process->exitCode, $process->stderr."\n".$snapshot);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors']);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['routes'] ?? null);
        self::assertTrue($result['sections']['routes']['complete']);
    }

    public function testRejectsVersionsWithoutAReleaseBranch(): void
    {
        (new AutoloaderFixtureBuilder($this->workspace))->writeAutoloader('dev-main');

        $process = $this->bridge->run([]);

        self::assertSame(1, $process->exitCode);
        self::assertSame('Symfony FrameworkBundle dev-main does not identify a release branch.', rtrim($process->stderr, "\n"));
    }
}
