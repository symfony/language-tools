<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;

final class BridgeTest extends AbstractBridgeTestCase
{
    #[DataProvider('versionProvider')]
    public function testReportsProjectMetadataWithoutAStaticBranchList(string $version): void
    {
        $this->writeAutoloader($version);

        exec(\sprintf(
            '%s %s --project=%s --environment=test --debug=0 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsArray($result['project'] ?? null);
        self::assertSame($version, $result['project']['symfonyVersion']);
        self::assertSame('42.7', $result['project']['symfonyBranch']);
        self::assertSame('test', $result['project']['environment']);
        self::assertFalse($result['project']['debug']);
    }

    /** @return iterable<string, array{string}> */
    public static function versionProvider(): iterable
    {
        yield 'release' => ['42.7.3'];
        yield 'prefixed release' => ['v42.7.3'];
        yield 'prerelease' => ['42.7.0-RC1'];
    }

    public function testKeepsStrayProjectOutputOffTheStdoutPayload(): void
    {
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string
                {
                    return '42.7.3';
                }
            }
            namespace App;
            echo "stray autoload output\n";
            trigger_error('Loading something deprecated.', \E_USER_DEPRECATED);
            PHP);
        $stdoutFile = $this->temporaryDirectory.'/stdout.log';
        $stderrFile = $this->temporaryDirectory.'/stderr.log';

        exec(\sprintf(
            '%s -d display_errors=1 -d error_reporting=-1 %s --project=%s --environment=test --debug=0 1>%s 2>%s',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
            escapeshellarg($stdoutFile),
            escapeshellarg($stderrFile),
        ), $output, $exitCode);

        $stdout = (string) file_get_contents($stdoutFile);
        $stderr = (string) file_get_contents($stderrFile);
        @unlink($stdoutFile);
        @unlink($stderrFile);
        self::assertSame(0, $exitCode, $stdout.$stderr);
        $result = json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsArray($result['project'] ?? null);
        self::assertSame('42.7.3', $result['project']['symfonyVersion']);
        self::assertStringContainsString('stray autoload output', $stderr);
        self::assertStringContainsString('Deprecated', $stderr);
    }

    public function testRebuildsContainerCacheBeforeLoadingSections(): void
    {
        $this->writeRouteApplication();
        mkdir($this->temporaryDirectory.'/var/cache', 0777, true);
        file_put_contents($this->temporaryDirectory.'/var/cache/marker', 'stale');

        exec(\sprintf(
            '%s %s --project=%s --sections=routes --rebuild-container=1 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertFileDoesNotExist($this->temporaryDirectory.'/var/cache/marker');
        @rmdir($this->temporaryDirectory.'/var');
    }

    public function testTargetedRefreshDiscardsStaleTranslationCatalogueCaches(): void
    {
        $this->writeRouteApplication();
        mkdir($this->temporaryDirectory.'/var/cache/translations', 0777, true);
        $catalogue = $this->temporaryDirectory.'/var/cache/translations/catalogue.en.stale.php';
        file_put_contents($catalogue, '<?php return [];');

        exec(\sprintf(
            '%s %s --project=%s --sections=translations --targeted-refresh=1 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertFileDoesNotExist($catalogue);
        $this->removeVarDirectory();
    }

    public function testInitialIndexKeepsTranslationCatalogueCaches(): void
    {
        $this->writeRouteApplication();
        mkdir($this->temporaryDirectory.'/var/cache/translations', 0777, true);
        $catalogue = $this->temporaryDirectory.'/var/cache/translations/catalogue.en.stale.php';
        file_put_contents($catalogue, '<?php return [];');

        exec(\sprintf(
            '%s %s --project=%s --sections=translations 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertFileExists($catalogue);
        $this->removeVarDirectory();
    }

    public function testDoesNotExposeApplicationExceptionsInSnapshot(): void
    {
        $this->writeRouteApplication();
        $autoload = $this->temporaryDirectory.'/vendor/autoload.php';
        $contents = str_replace(
            'public function __construct(string $environment, bool $debug) {}',
            'public function __construct(string $environment, bool $debug) { throw new \\RuntimeException(\'CANARY_RUNTIME_EXCEPTION\'); }',
            (string) file_get_contents($autoload),
            $count,
        );
        self::assertSame(1, $count);
        file_put_contents($autoload, $contents);

        exec(\sprintf(
            '%s %s --project=%s --sections=routes 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        self::assertStringNotContainsString('CANARY_RUNTIME_EXCEPTION', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame(['status' => 'unavailable'], $result['configurationValidation'] ?? null);
        self::assertSame([[
            'section' => 'routes',
            'message' => 'Unable to load the "routes" runtime metadata section.',
        ]], $result['errors']);
    }

    public function testUsesOneKernelAndApplicationForAllRequestedSections(): void
    {
        $this->writeSharedKernelApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=routes,container,environment 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertSame(['routes', 'container', 'environment'], array_keys($result['sections']));
    }

    public function testKeepsCollectedSectionsWhenKernelShutdownFails(): void
    {
        $this->writeRouteApplication();
        $autoload = $this->temporaryDirectory.'/vendor/autoload.php';
        $contents = str_replace(
            'public function shutdown(): void {}',
            'public function shutdown(): void { throw new \\RuntimeException(\'CANARY_SHUTDOWN_EXCEPTION\'); }',
            (string) file_get_contents($autoload),
            $count,
        );
        self::assertSame(1, $count);
        file_put_contents($autoload, $contents);

        exec(\sprintf(
            '%s %s --project=%s --sections=routes 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        self::assertStringNotContainsString('CANARY_SHUTDOWN_EXCEPTION', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
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
        $this->writeRouteApplication('Acme');
        file_put_contents($this->temporaryDirectory.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Acme\\' => 'src/']],
        ], \JSON_THROW_ON_ERROR));
        mkdir($this->temporaryDirectory.'/src');
        file_put_contents($this->temporaryDirectory.'/src/Kernel.php', '<?php');

        exec(\sprintf(
            '%s %s --project=%s --sections=routes 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        @unlink($this->temporaryDirectory.'/composer.json');
        @unlink($this->temporaryDirectory.'/src/Kernel.php');
        @rmdir($this->temporaryDirectory.'/src');
        self::assertSame(0, $exitCode, $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame([], $result['errors']);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['routes'] ?? null);
        self::assertTrue($result['sections']['routes']['complete']);
    }

    public function testSkipsPsr4KernelCandidatesThatAreNotKernels(): void
    {
        $this->writeMultiRootKernelApplication();
        file_put_contents($this->temporaryDirectory.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Tests\\' => 'tests/', 'Acme\\' => 'src/']],
        ], \JSON_THROW_ON_ERROR));
        mkdir($this->temporaryDirectory.'/src');
        mkdir($this->temporaryDirectory.'/tests');
        file_put_contents($this->temporaryDirectory.'/src/Kernel.php', '<?php');
        file_put_contents($this->temporaryDirectory.'/tests/Kernel.php', '<?php');

        exec(\sprintf(
            '%s %s --project=%s --sections=routes 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        @unlink($this->temporaryDirectory.'/composer.json');
        @unlink($this->temporaryDirectory.'/src/Kernel.php');
        @unlink($this->temporaryDirectory.'/tests/Kernel.php');
        @rmdir($this->temporaryDirectory.'/src');
        @rmdir($this->temporaryDirectory.'/tests');
        self::assertSame(0, $exitCode, $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame([], $result['errors']);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['routes'] ?? null);
        self::assertTrue($result['sections']['routes']['complete']);
    }

    public function testRejectsVersionsWithoutAReleaseBranch(): void
    {
        $this->writeAutoloader('dev-main');

        exec(\sprintf(
            '%s %s --project=%s 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        self::assertSame(1, $exitCode);
        self::assertSame('Symfony FrameworkBundle dev-main does not identify a release branch.', implode("\n", $output));
    }
}
