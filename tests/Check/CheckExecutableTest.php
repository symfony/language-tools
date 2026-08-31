<?php

namespace Symfony\Lsp\Tests\Check;

use Amp\Process\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Check\CheckCommand;
use Symfony\Lsp\Runtime\UnsupportedSymfonyVersionException;
use Symfony\Lsp\Server\ServerVersion;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\Future\await;

/**
 * @phpstan-type SarifNotification array{descriptor: array{id: string}}
 * @phpstan-type SarifInvocation array{executionSuccessful: bool, exitCode: int, toolConfigurationNotifications?: list<SarifNotification>}
 * @phpstan-type SarifLocation array{physicalLocation: array{artifactLocation: array{uri: string}}}
 * @phpstan-type SarifResult array{ruleId: string, locations: list<SarifLocation>}
 * @phpstan-type SarifRun array{tool: array{driver: array{rules: list<array{id: string}>}}, invocations: list<SarifInvocation>, results: list<SarifResult>}
 * @phpstan-type SarifReport array{version: string, runs: list<SarifRun>}
 * @phpstan-type CheckReport array{
 *     complete: bool,
 *     projects: list<array{environment: string, analysis: array{mode: string, reason: string|null}}>,
 *     diagnostics: list<array{code: string, path: string}>,
 *     summary: array{blocking: int, stale: int},
 *     errors: list<array{category: string, message: string, cause?: array{class: string, message: string}}>
 * }
 */
final class CheckExecutableTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/symfony-lsp-check-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/config', 0777, true);
        file_put_contents($this->directory.'/composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($this->directory.'/config/services.yaml', "parameters:\n    broken: '%env(APP_SECRET%'\n");
        file_put_contents($this->directory.'/releases.json', json_encode([
            'supported_versions' => ['8.0'],
        ], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testUsesApplicationSpecificExitStatuses(): void
    {
        self::assertSame(0, CheckCommand::EXIT_SUCCESS);
        self::assertSame(10, CheckCommand::EXIT_DIAGNOSTICS);
        self::assertSame(11, CheckCommand::EXIT_INVOCATION);
        self::assertSame(12, CheckCommand::EXIT_OPERATIONAL);
    }

    public function testReportsTheExactVersionContract(): void
    {
        $result = $this->execute(['--version']);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame('Symfony Language Tools '.(new ServerVersion())->value()."\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testRejectsUnknownTopLevelCommands(): void
    {
        $result = $this->execute(['unknown-command']);

        self::assertSame(CheckCommand::EXIT_INVOCATION, $result['exitCode']);
        self::assertSame('', $result['stdout']);
        self::assertSame('Unknown command "unknown-command".'.\PHP_EOL, $result['stderr']);
    }

    public function testUsesTheSymfonyCliAsTheDefaultPhpCommand(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('The source executable integration requires Unix executable scripts.');
        }

        $marker = $this->directory.'/symfony-cli-command.json';
        $symfonyCli = $this->directory.'/symfony';
        file_put_contents($symfonyCli, "#!/usr/bin/env php\n<?php\nfile_put_contents(".var_export($marker, true).", json_encode(array_slice(\$argv, 1), JSON_THROW_ON_ERROR));\nexit(1);\n");
        chmod($symfonyCli, 0700);

        $result = $this->execute(
            ['check', '--format=json', '--workspace='.$this->directory],
            ['SYMFONY_LSP_SYMFONY_CLI' => $symfonyCli],
        );
        /** @var list<string> $command */
        $command = json_decode((string) file_get_contents($marker), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $result['exitCode']);
        self::assertSame('php', $command[0]);
        self::assertStringEndsWith('/bridge.php', str_replace('\\', '/', $command[1]));
    }

    public function testReportsSavedFileDiagnosticsWithoutAnLspClient(): void
    {
        $result = $this->execute(['check', '--source-only', '--format=json', '--workspace='.$this->directory, 'config/**/*.yaml']);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $result['exitCode'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        self::assertTrue($report['complete']);
        self::assertSame('source-only', $report['projects'][0]['analysis']['mode']);
        self::assertSame('runtime-indexing-disabled', $report['projects'][0]['analysis']['reason']);
        self::assertSame('env.malformed_chain', $report['diagnostics'][0]['code']);
        self::assertSame('config/services.yaml', $report['diagnostics'][0]['path']);
        self::assertSame(1, $report['summary']['blocking']);
    }

    public function testRendersSarifForDiagnosticsAndCodeLists(): void
    {
        $result = $this->execute(['check', '--source-only', '--format=sarif', '--workspace='.$this->directory, 'config/services.yaml']);
        /** @var SarifReport $sarif */
        $sarif = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        $codes = $this->execute(['check', '--format=sarif', '--list-codes']);
        /** @var SarifReport $codeSarif */
        $codeSarif = json_decode($codes['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $result['exitCode'], $result['stderr']);
        self::assertSame('2.1.0', $sarif['version']);
        self::assertTrue($sarif['runs'][0]['invocations'][0]['executionSuccessful']);
        self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $sarif['runs'][0]['invocations'][0]['exitCode']);
        self::assertSame('env.malformed_chain', $sarif['runs'][0]['results'][0]['ruleId']);
        self::assertSame('config/services.yaml', $sarif['runs'][0]['results'][0]['locations'][0]['physicalLocation']['artifactLocation']['uri']);
        self::assertSame(CheckCommand::EXIT_SUCCESS, $codes['exitCode'], $codes['stderr']);
        self::assertSame([], $codeSarif['runs'][0]['results']);
        $listedCodes = array_column($codeSarif['runs'][0]['tool']['driver']['rules'], 'id');
        self::assertContains('env.malformed_chain', $listedCodes);
        self::assertContains('console.unknown_argument', $listedCodes);
        self::assertContains('console.unknown_option', $listedCodes);
    }

    public function testExcludesConfiguredPathsUnlessTheyAreExplicitlySelected(): void
    {
        file_put_contents($this->directory.'/.symfony-lsp.json', json_encode([
            'version' => 1,
            'excludePaths' => ['config/**'],
        ], \JSON_THROW_ON_ERROR));

        $default = $this->execute(['check', '--source-only', '--format=json', '--workspace='.$this->directory]);
        $defaultReport = $this->decodeReport($default['stdout']);
        $explicit = $this->execute(['check', '--source-only', '--format=json', '--workspace='.$this->directory, 'config/services.yaml']);
        $explicitReport = $this->decodeReport($explicit['stdout']);

        self::assertSame(CheckCommand::EXIT_SUCCESS, $default['exitCode'], $default['stderr']);
        self::assertSame([], $defaultReport['diagnostics']);
        self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $explicit['exitCode'], $explicit['stderr']);
        self::assertSame('env.malformed_chain', $explicitReport['diagnostics'][0]['code']);
    }

    public function testBlockingCodeSelectionDoesNotFilterOtherDiagnostics(): void
    {
        $result = $this->execute([
            'check',
            '--source-only',
            '--format=json',
            '--workspace='.$this->directory,
            '--fail-on=config.deprecated_key',
        ]);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame('env.malformed_chain', $report['diagnostics'][0]['code']);
        self::assertSame(0, $report['summary']['blocking']);
    }

    public function testCommandLineSettingsOverrideCheckedInSettings(): void
    {
        file_put_contents($this->directory.'/.symfony-lsp.json', json_encode([
            'version' => 1,
            'environment' => 'prod',
            'runtimeIndexing' => false,
        ], \JSON_THROW_ON_ERROR));

        $result = $this->execute([
            'check',
            '--format=json',
            '--workspace='.$this->directory,
            '--environment=test',
        ]);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $result['exitCode'], $result['stderr']);
        self::assertSame('test', $report['projects'][0]['environment']);
        self::assertSame('source-only', $report['projects'][0]['analysis']['mode']);
    }

    public function testReportsVendorConfigurationFailuresAndIncompleteAnalysis(): void
    {
        file_put_contents($this->directory.'/config/services.yaml', "parameters:\n    valid: value\n");
        mkdir($this->directory.'/vendor');
        file_put_contents($this->directory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '8.0.6'; }
            }
            namespace Symfony\Component\Yaml\Exception;
            final class ParseException extends \RuntimeException
            {
                public function getParsedFile(): string { return dirname(__DIR__).'/config/services.yaml'; }
                public function getParsedLine(): int { return 2; }
            }
            namespace App;
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function boot(): void { throw new \Symfony\Component\Yaml\Exception\ParseException('CANARY_SECRET_CONFIGURATION_VALUE'); }
                public function shutdown(): void {}
            }
            PHP);

        $result = $this->execute(['check', '--format=json', '--workspace='.$this->directory, 'config/services.yaml']);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $result['exitCode']);
        self::assertFalse($report['complete']);
        self::assertSame('configuration', $report['errors'][0]['category']);
        self::assertSame('config.malformed_structure', $report['diagnostics'][0]['code']);
        self::assertSame('config/services.yaml', $report['diagnostics'][0]['path']);
        self::assertSame(1, $report['summary']['blocking']);
        self::assertStringNotContainsString('CANARY_SECRET', $result['stdout'].$result['stderr']);
    }

    public function testKeepsUnmappableVendorConfigurationFailuresAtProjectLevel(): void
    {
        file_put_contents($this->directory.'/config/services.yaml', "parameters:\n    valid: value\n");
        mkdir($this->directory.'/vendor');
        file_put_contents($this->directory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '8.0.6'; }
            }
            namespace Symfony\Component\Config\Definition\Exception;
            final class InvalidConfigurationException extends \RuntimeException
            {
                public function getPath(): ?string { return 'framework.router'; }
            }
            namespace App;
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function boot(): void { throw new \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException('CANARY_SECRET_CONFIGURATION_VALUE'); }
                public function shutdown(): void {}
            }
            PHP);

        $result = $this->execute(['check', '--format=json', '--workspace='.$this->directory, 'config/services.yaml']);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $result['exitCode']);
        self::assertFalse($report['complete']);
        self::assertSame('configuration', $report['errors'][0]['category']);
        self::assertSame([], $report['diagnostics']);
        self::assertSame(0, $report['summary']['blocking']);
        self::assertStringNotContainsString('CANARY_SECRET', $result['stdout'].$result['stderr']);
    }

    public function testReportsUnsupportedSymfonyVersions(): void
    {
        file_put_contents($this->directory.'/composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^5.4'],
        ], \JSON_THROW_ON_ERROR));
        mkdir($this->directory.'/vendor');
        file_put_contents($this->directory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '5.4.45'; }
            }
            PHP);

        $result = $this->execute(['check', '--format=json', '--workspace='.$this->directory]);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $result['exitCode']);
        self::assertFalse($report['complete']);
        self::assertSame('Symfony 5.4 is not supported by Symfony Language Tools.', $report['errors'][0]['message']);
        self::assertSame(UnsupportedSymfonyVersionException::class, $report['errors'][0]['cause']['class'] ?? null);
    }

    public function testDoesNotReportACleanResultWhenRuntimeIndexingFails(): void
    {
        $result = $this->execute(['check', '--format=json', '--workspace='.$this->directory]);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $result['exitCode']);
        self::assertFalse($report['complete']);
        self::assertSame('operational', $report['errors'][0]['category']);
        self::assertIsArray($report['errors'][0]['cause'] ?? null);
        self::assertStringNotContainsString('APP_SECRET', $result['stdout'].$result['stderr']);
    }

    public function testDoesNotOverlayExplicitExcludedFilesAfterOperationalRuntimeFailure(): void
    {
        file_put_contents($this->directory.'/.symfony-lsp.json', json_encode([
            'version' => 1,
            'excludePaths' => ['config/**'],
        ], \JSON_THROW_ON_ERROR));
        mkdir($this->directory.'/vendor');
        file_put_contents($this->directory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '8.0.6'; }
            }
            namespace App;
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function boot(): void { throw new \RuntimeException('token=CANARY_RUNTIME_SECRET'); }
                public function shutdown(): void {}
            }
            PHP);

        $result = $this->execute(['check', '--format=json', '--workspace='.$this->directory, 'config/services.yaml']);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $result['exitCode']);
        self::assertFalse($report['complete']);
        self::assertSame([], $report['diagnostics']);
        self::assertSame('operational', $report['errors'][0]['category']);
        self::assertIsArray($report['errors'][0]['cause'] ?? null);
        self::assertStringNotContainsString('CANARY_RUNTIME_SECRET', $result['stdout'].$result['stderr']);
    }

    public function testBaselineCreationMatchingAndStrictStaleEnforcement(): void
    {
        $baseline = $this->directory.'/baseline.json';
        $create = $this->execute([
            'check',
            '--source-only',
            '--format=json',
            '--workspace='.$this->directory,
            '--baseline=baseline.json',
            '--generate-baseline',
        ]);
        self::assertSame(0, $create['exitCode'], $create['stderr']);
        self::assertFileExists($baseline);
        self::assertStringNotContainsString('APP_SECRET', (string) file_get_contents($baseline));
        $baselineHash = hash_file('sha256', $baseline);

        $matched = $this->execute([
            'check',
            '--source-only',
            '--format=json',
            '--workspace='.$this->directory,
            '--baseline=baseline.json',
        ]);
        self::assertSame(0, $matched['exitCode'], $matched['stderr']);
        self::assertSame($baselineHash, hash_file('sha256', $baseline));

        file_put_contents($this->directory.'/config/services.yaml', "parameters:\n    valid: '%env(APP_SECRET)%'\n");
        $strict = $this->execute([
            'check',
            '--source-only',
            '--format=json',
            '--workspace='.$this->directory,
            '--baseline=baseline.json',
            '--strict-baseline',
        ]);
        $report = $this->decodeReport($strict['stdout']);

        self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $strict['exitCode'], $strict['stderr']);
        self::assertSame(1, $report['summary']['stale']);
        self::assertSame(1, $report['summary']['blocking']);
    }

    public function testRejectsUnreadableApplicationDirectories(): void
    {
        $directory = $this->directory.'/blocked';
        mkdir($directory);
        chmod($directory, 0000);
        try {
            if (is_readable($directory)) {
                self::markTestSkipped('The platform cannot make the directory unreadable.');
            }

            $result = $this->execute(['check', '--source-only', '--format=json', '--workspace='.$this->directory]);
            $report = $this->decodeReport($result['stdout']);

            self::assertSame(CheckCommand::EXIT_INVOCATION, $result['exitCode']);
            self::assertFalse($report['complete']);
            self::assertStringContainsString('unreadable', $result['stderr']);
        } finally {
            chmod($directory, 0700);
        }
    }

    public function testExplicitSelectionIgnoresUnrelatedExcludedSymlinks(): void
    {
        $external = $this->directory.'-external.php';
        file_put_contents($external, '<?php');
        mkdir($this->directory.'/fixtures');
        try {
            if (!@symlink($external, $this->directory.'/fixtures/external.php')) {
                self::markTestSkipped('The platform cannot create file symlinks.');
            }
            file_put_contents($this->directory.'/.symfony-lsp.json', json_encode([
                'version' => 1,
                'excludePaths' => ['fixtures/**'],
            ], \JSON_THROW_ON_ERROR));

            $result = $this->execute(['check', '--source-only', '--format=json', '--workspace='.$this->directory, 'config/services.yaml']);
            $report = $this->decodeReport($result['stdout']);

            self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $result['exitCode'], $result['stderr']);
            self::assertSame('env.malformed_chain', $report['diagnostics'][0]['code']);
        } finally {
            @unlink($external);
        }
    }

    public function testRejectsApplicationSymlinksThatResolveOutsideTheProject(): void
    {
        $external = $this->directory.'-external.php';
        file_put_contents($external, '<?php');
        try {
            if (!@symlink($external, $this->directory.'/config/external.php')) {
                self::markTestSkipped('The platform cannot create file symlinks.');
            }

            $result = $this->execute(['check', '--source-only', '--format=json', '--workspace='.$this->directory]);
            $report = $this->decodeReport($result['stdout']);

            self::assertSame(CheckCommand::EXIT_INVOCATION, $result['exitCode']);
            self::assertFalse($report['complete']);
            self::assertStringContainsString('resolves outside', $result['stderr']);
        } finally {
            @unlink($external);
        }
    }

    public function testAppliesTheDeadlineToTheCompleteCheck(): void
    {
        $result = $this->execute([
            'check',
            '--source-only',
            '--format=json',
            '--workspace='.$this->directory,
            '--timeout=0.000001',
        ]);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $result['exitCode']);
        self::assertFalse($report['complete']);
        self::assertStringContainsString('timed out', $result['stderr']);
    }

    public function testCancelsDuringSourceRefreshBeforeDiagnostics(): void
    {
        if ('Windows' === \PHP_OS_FAMILY || !\function_exists('pcntl_signal')) {
            self::markTestSkipped('The source executable integration requires Unix signals.');
        }
        for ($index = 0; $index < 256; ++$index) {
            file_put_contents(\sprintf('%s/config/service_%03d.yaml', $this->directory, $index), "parameters:\n    value: '%env(APP_SECRET%\'\n");
        }

        $process = $this->start(['check', '--source-only', '--format=json', '--workspace='.$this->directory]);
        $deadline = microtime(true) + 10;
        do {
            $indexFiles = glob($this->directory.'/var/symfony-lsp/*/index/source.jsonl.tmp') ?: [];
            if ([] !== $indexFiles) {
                break;
            }
            usleep(1_000);
        } while ($process->isRunning() && microtime(true) < $deadline);
        self::assertNotSame([], $indexFiles, 'The source refresh did not start before the deadline.');
        $process->signal(\SIGINT);
        $result = $this->awaitProcess($process);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $result['exitCode'], $result['stderr']);
        self::assertFalse($report['complete']);
        self::assertSame([], $report['diagnostics']);
        self::assertStringContainsString('was canceled', $report['errors'][0]['message'] ?? '');
        self::assertStringNotContainsString('timed out', $result['stderr']);
    }

    public function testRejectsMissingExplicitConfigurationFiles(): void
    {
        $result = $this->execute([
            'check',
            '--format=json',
            '--workspace='.$this->directory,
            '--config=missing.json',
        ]);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_INVOCATION, $result['exitCode']);
        self::assertFalse($report['complete']);
        self::assertStringContainsString('does not exist', $result['stderr']);
    }

    public function testRejectsEveryInvalidExplicitProjectRoot(): void
    {
        $result = $this->execute([
            'check',
            '--source-only',
            '--format=json',
            '--workspace='.$this->directory,
            '--project-root=.',
            '--project-root=missing',
        ]);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_INVOCATION, $result['exitCode']);
        self::assertFalse($report['complete']);
        self::assertStringContainsString('was not discovered', $result['stderr']);
    }

    public function testKeepsSarifValidForInvocationFailures(): void
    {
        $result = $this->execute(['check', '--format=sarif', '--workspace='.$this->directory, 'missing.php']);
        /** @var SarifReport $sarif */
        $sarif = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(CheckCommand::EXIT_INVOCATION, $result['exitCode']);
        self::assertFalse($sarif['runs'][0]['invocations'][0]['executionSuccessful']);
        self::assertSame(CheckCommand::EXIT_INVOCATION, $sarif['runs'][0]['invocations'][0]['exitCode']);
        self::assertSame('symfony.check.invocation', $sarif['runs'][0]['invocations'][0]['toolConfigurationNotifications'][0]['descriptor']['id'] ?? null);
        self::assertStringContainsString('does not exist', $result['stderr']);
    }

    public function testKeepsJsonValidForInvocationFailures(): void
    {
        $result = $this->execute(['check', '--format=json', '--workspace='.$this->directory, 'missing.php']);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_INVOCATION, $result['exitCode']);
        self::assertFalse($report['complete']);
        self::assertSame('invocation', $report['errors'][0]['category']);
        self::assertStringContainsString('does not exist', $result['stderr']);
    }

    #[DataProvider('machineFormats')]
    public function testRendersOptionParseFailuresInTheRequestedMachineFormat(string $format): void
    {
        $result = $this->execute(['check', '--unknown=value', '--format='.$format]);

        self::assertSame(CheckCommand::EXIT_INVOCATION, $result['exitCode']);
        self::assertSame('Unknown check option "--unknown".'.\PHP_EOL, $result['stderr']);
        if ('json' === $format) {
            $report = $this->decodeReport($result['stdout']);
            self::assertSame('invocation', $report['errors'][0]['category'] ?? null);
        } else {
            /** @var SarifReport $report */
            $report = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('symfony.check.invocation', $report['runs'][0]['invocations'][0]['toolConfigurationNotifications'][0]['descriptor']['id'] ?? null);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function machineFormats(): iterable
    {
        yield 'JSON' => ['json'];
        yield 'SARIF' => ['sarif'];
    }

    /** @return CheckReport */
    private function decodeReport(string $json): array
    {
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('The check report is not an object.');
        }

        /** @var CheckReport $report */
        $report = $decoded;

        return $report;
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function execute(array $arguments, array $environment = []): array
    {
        return $this->awaitProcess($this->start($arguments, $environment));
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     */
    private function start(array $arguments, array $environment = []): Process
    {
        $root = \dirname(__DIR__, 2);
        $inheritedEnvironment = getenv();

        return Process::start(
            [Path::join($root, 'bin/symfony-lsp'), ...$arguments],
            workingDirectory: $root,
            environment: [
                ...$inheritedEnvironment,
                'SYMFONY_LSP_RELEASE_METADATA_URL' => $this->directory.'/releases.json',
                ...$environment,
            ],
            options: ['bypass_shell' => true],
        );
    }

    /** @return array{stdout: string, stderr: string, exitCode: int} */
    private function awaitProcess(Process $process): array
    {
        $futures = [
            'stdout' => async(static fn (): string => buffer($process->getStdout())),
            'stderr' => async(static fn (): string => buffer($process->getStderr())),
            'exitCode' => async(static fn (): int => $process->join()),
        ];

        /** @var array{stdout: string, stderr: string, exitCode: int} $result */
        $result = await($futures);

        return $result;
    }
}
