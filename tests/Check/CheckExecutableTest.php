<?php

namespace Symfony\Lsp\Tests\Check;

use Amp\Process\Process;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Check\CheckCommand;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\Future\await;

/**
 * @phpstan-type CheckReport array{
 *     complete: bool,
 *     projects: list<array{environment: string, analysis: array{mode: string, reason: string|null}}>,
 *     diagnostics: list<array{code: string, path: string}>,
 *     summary: array{blocking: int, stale: int},
 *     errors: list<array{category: string, cause?: array{class: string, message: string}}>
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

    public function testKeepsJsonValidForInvocationFailures(): void
    {
        $result = $this->execute(['check', '--format=json', '--workspace='.$this->directory, 'missing.php']);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_INVOCATION, $result['exitCode']);
        self::assertFalse($report['complete']);
        self::assertSame('invocation', $report['errors'][0]['category']);
        self::assertStringContainsString('does not exist', $result['stderr']);
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
     * @param list<string> $arguments
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function execute(array $arguments): array
    {
        $root = \dirname(__DIR__, 2);
        $process = Process::start(
            [Path::join($root, 'bin/symfony-lsp'), ...$arguments],
            workingDirectory: $root,
            environment: getenv(),
            options: ['bypass_shell' => true],
        );
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
