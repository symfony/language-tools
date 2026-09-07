<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\Dogfood\DiagnosticCheckHarness;
use Symfony\Lsp\Tools\Dogfood\DiagnosticCheckResult;
use Symfony\Lsp\Tools\Dogfood\ProcessResult;
use Symfony\Lsp\Tools\Dogfood\ProjectConfiguration;

final class DiagnosticCheckHarnessTest extends TestCase
{
    private string $directory;
    private FakeProcessRunner $processes;

    protected function setUp(): void
    {
        $this->directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-diagnostic-check-'.bin2hex(random_bytes(8)));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testRunsTheCheckOnceInTheApplicationRoot(): void
    {
        $result = $this->check(self::report());

        self::assertCount(1, $this->processes->calls);
        self::assertSame([
            '/bin/symfony-lsp',
            'check',
            '--format=json',
            '--profile',
            '--runtime-indexing',
            '--workspace='.$this->directory,
            '--environment=prod',
            '--bridge-timeout=120',
            '--timeout=300',
        ], $this->processes->calls[0]['command']);
        self::assertSame($this->directory, $this->processes->calls[0]['directory']);
        self::assertSame(310.0, $this->processes->calls[0]['timeout']);
        self::assertSame(['DATABASE_URL' => 'mysql://root@127.0.0.1:9/app'], $this->processes->calls[0]['environment']);
        self::assertTrue($result->ok());
        self::assertSame(41, $result->analyzedFiles);
        self::assertGreaterThanOrEqual(0.0, $result->milliseconds);
    }

    public function testDerivesTheBudgetsFromTheProjectIndexTimeout(): void
    {
        $this->check(self::report(), configuration: self::configuration(['indexTimeout' => 300]));

        self::assertContains('--bridge-timeout=300', $this->processes->calls[0]['command']);
        self::assertContains('--timeout=660', $this->processes->calls[0]['command']);
        self::assertSame(670.0, $this->processes->calls[0]['timeout']);
    }

    public function testProjectsDiagnosticsWithoutApplicationValues(): void
    {
        $result = $this->check(self::report(['diagnostics' => [
            self::diagnostic('src/Controller/BlogController.php', 'route.not_found', ['message' => 'The route "s3cr3t_admin" does not exist.']),
        ]]), exitCode: 10);

        self::assertTrue($result->ok());
        self::assertSame([[
            'path' => 'src/Controller/BlogController.php',
            'code' => 'route.not_found',
            'severity' => 'error',
            'range' => [
                'start' => ['line' => 3, 'character' => 4],
                'end' => ['line' => 3, 'character' => 9],
            ],
        ]], $result->diagnostics);
        $serialized = json_encode($result->toArray(), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('s3cr3t_admin', $serialized);
        self::assertStringNotContainsString('message', $serialized);
        self::assertStringNotContainsString($this->directory, $serialized);
    }

    public function testPreservesDuplicateDiagnosticsInADeterministicOrder(): void
    {
        $result = $this->check(self::report(['diagnostics' => [
            self::diagnostic('templates/base.html.twig', 'template.not_found'),
            self::diagnostic('config/services.yaml', 'service.not_found', ['severity' => 'warning']),
            self::diagnostic('config/services.yaml', 'service.not_found'),
            self::diagnostic('config/services.yaml', 'service.not_found'),
        ]]), exitCode: 10);

        self::assertTrue($result->ok());
        self::assertSame([
            ['config/services.yaml', 'service.not_found', 'error'],
            ['config/services.yaml', 'service.not_found', 'error'],
            ['config/services.yaml', 'service.not_found', 'warning'],
            ['templates/base.html.twig', 'template.not_found', 'error'],
        ], array_map(
            static fn (array $diagnostic): array => [$diagnostic['path'], $diagnostic['code'], $diagnostic['severity']],
            $result->diagnostics,
        ));
    }

    public function testOrdersDiagnosticsByPosition(): void
    {
        $result = $this->check(self::report(['diagnostics' => [
            self::diagnostic('config/services.yaml', 'service.not_found', ['range' => self::range(9, 2, 9, 4)]),
            self::diagnostic('config/services.yaml', 'service.not_found', ['range' => self::range(2, 8, 2, 9)]),
            self::diagnostic('config/services.yaml', 'service.not_found', ['range' => self::range(2, 1, 4, 0)]),
        ]]), exitCode: 10);

        self::assertSame([[2, 1], [2, 8], [9, 2]], array_map(
            static fn (array $diagnostic): array => [$diagnostic['range']['start']['line'], $diagnostic['range']['start']['character']],
            $result->diagnostics,
        ));
    }

    #[DataProvider('rejectionProvider')]
    public function testRejectsUntrustworthyRuns(string $standardOutput, int $exitCode, string $failure): void
    {
        $result = $this->check($standardOutput, exitCode: $exitCode);

        self::assertFalse($result->ok());
        self::assertSame($failure, $result->failure);
        self::assertSame([], $result->diagnostics);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function rejectionProvider(): iterable
    {
        yield 'hidden provider failure' => [self::report(['errors' => [
            ['category' => 'operational', 'message' => 'Template diagnostics failed.', 'provider' => 'template'],
        ]]), 0, 'check-errors'];
        yield 'incomplete analysis' => [self::report(['complete' => false]), 12, 'analysis-incomplete'];
        yield 'operational exit code' => [self::report(), 12, 'exit-code'];
        yield 'invocation exit code' => [self::report(), 11, 'exit-code'];
        yield 'unknown exit code' => [self::report(), 1, 'exit-code'];
        yield 'truncated json' => [substr(self::report(), 0, 40), 0, 'report-not-json'];
        yield 'empty output' => ['', 0, 'report-not-json'];
        yield 'scalar output' => ['"done"', 0, 'report-not-json'];
        yield 'unknown schema version' => [self::report(['schemaVersion' => 2]), 0, 'report-schema'];
        yield 'missing diagnostics' => [self::report(['diagnostics' => null]), 0, 'report-schema'];
        yield 'no analyzed project' => [self::report(['projects' => []]), 0, 'no-projects'];
        yield 'source-only project' => [self::report(['projects' => [self::project([
            'analysis' => ['mode' => 'source-only', 'reason' => 'debug-disabled'],
            'runtime' => ['state' => 'disabled', 'reason' => 'debug-disabled'],
        ])]]), 0, 'project-not-runtime-ready'];
        yield 'stale runtime metadata' => [self::report(['projects' => [self::project([
            'runtime' => ['state' => 'stale'],
        ])]]), 0, 'project-not-runtime-ready'];
        yield 'incomplete project' => [self::report(['projects' => [self::project(['complete' => false])]]), 0, 'project-not-runtime-ready'];
        yield 'baseline file' => [self::report(['baseline' => [
            'path' => '.symfony-lsp-baseline.json',
            'mode' => 'none',
            'strict' => false,
            'stale' => [],
        ]]), 0, 'baseline-active'];
        yield 'baseline generation' => [self::report(['baseline' => [
            'path' => null,
            'mode' => 'create',
            'strict' => false,
            'stale' => [],
        ]]), 0, 'baseline-active'];
        yield 'stale baseline entries' => [self::report(['baseline' => [
            'path' => null,
            'mode' => 'none',
            'strict' => false,
            'stale' => [['project' => '.', 'path' => 'config/services.yaml']],
        ]]), 0, 'baseline-active'];
        yield 'baseline-suppressed diagnostic' => [self::report(['diagnostics' => [
            self::diagnostic('config/services.yaml', 'service.not_found', ['baseline' => 'matched']),
        ]]), 10, 'baseline-active'];
        yield 'absolute diagnostic path' => [self::report(['diagnostics' => [
            self::diagnostic('/home/dogfood/checkout/config/services.yaml', 'service.not_found'),
        ]]), 10, 'diagnostic-path-invalid'];
        yield 'escaping diagnostic path' => [self::report(['diagnostics' => [
            self::diagnostic('../secrets/services.yaml', 'service.not_found'),
        ]]), 10, 'diagnostic-path-invalid'];
        yield 'empty diagnostic path' => [self::report(['diagnostics' => [
            self::diagnostic('', 'service.not_found'),
        ]]), 10, 'diagnostic-path-invalid'];
        yield 'diagnostic without a range' => [self::report(['diagnostics' => [
            self::diagnostic('config/services.yaml', 'service.not_found', ['range' => null]),
        ]]), 10, 'diagnostic-invalid'];
        yield 'diagnostic with a negative position' => [self::report(['diagnostics' => [
            self::diagnostic('config/services.yaml', 'service.not_found', ['range' => self::range(-1, 0, 0, 0)]),
        ]]), 10, 'diagnostic-invalid'];
        yield 'diagnostic without a code' => [self::report(['diagnostics' => [
            self::diagnostic('config/services.yaml', ''),
        ]]), 10, 'diagnostic-invalid'];
        yield 'diagnostic with an unknown severity' => [self::report(['diagnostics' => [
            self::diagnostic('config/services.yaml', 'service.not_found', ['severity' => 'unknown']),
        ]]), 10, 'diagnostic-invalid'];
    }

    public function testRejectsATimedOutCheck(): void
    {
        $result = $this->check(self::report(), timedOut: true);

        self::assertSame('process-timeout', $result->failure);
        self::assertNull($result->analyzedFiles);
    }

    #[DataProvider('invalidApplicationRootProvider')]
    public function testRejectsAnInvalidApplicationRootWithoutRunningTheCheck(string $applicationRoot): void
    {
        $result = $this->check(self::report(), applicationRoot: $applicationRoot);

        self::assertSame('application-root-invalid', $result->failure);
        self::assertNull($result->exitCode);
        self::assertSame(0.0, $result->milliseconds);
        self::assertSame([], $this->processes->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidApplicationRootProvider(): iterable
    {
        yield 'relative path' => ['var/dogfood/checkouts/application'];
        yield 'empty path' => [''];
        yield 'missing directory' => [Path::join(sys_get_temp_dir(), 'symfony-lsp-missing-'.bin2hex(random_bytes(8)))];
    }

    public function testReportsNoAnalyzedFileCountWithoutProfileMetadata(): void
    {
        $result = $this->check(self::report(['profile' => null]));

        self::assertTrue($result->ok());
        self::assertNull($result->analyzedFiles);
    }

    public function testSumsTheAnalyzedFileCountOfEveryProject(): void
    {
        $result = $this->check(self::report([
            'projects' => [self::project(), self::project(['id' => 'apps/api'])],
            'profile' => ['projects' => [['id' => '.', 'files' => 41], ['id' => 'apps/api', 'files' => 8]]],
        ]));

        self::assertSame(49, $result->analyzedFiles);
    }

    private function check(string $standardOutput, int $exitCode = 0, bool $timedOut = false, ?string $applicationRoot = null, ?ProjectConfiguration $configuration = null): DiagnosticCheckResult
    {
        $this->processes = new FakeProcessRunner(static fn (): ProcessResult => new ProcessResult($exitCode, $standardOutput, 'Runtime bridge took 1.2s.', $timedOut));
        $harness = new DiagnosticCheckHarness($this->processes, '/bin/symfony-lsp');

        return $harness->run($configuration ?? self::configuration(), $applicationRoot ?? $this->directory);
    }

    /** @param array{indexTimeout?: int} $overrides */
    private static function configuration(array $overrides = []): ProjectConfiguration
    {
        return new ProjectConfiguration(
            'application',
            'https://example.com/application.git',
            str_repeat('a', 40),
            null,
            'prod',
            'composer',
            false,
            $overrides['indexTimeout'] ?? 120,
            environmentVariables: ['DATABASE_URL' => 'mysql://root@127.0.0.1:9/app'],
        );
    }

    /** @param array<string, mixed> $overrides */
    private static function report(array $overrides = []): string
    {
        return json_encode(array_replace([
            'schemaVersion' => 1,
            'tool' => ['name' => 'Symfony Language Tools', 'version' => '1.2.3'],
            'complete' => true,
            'projects' => [self::project()],
            'profile' => ['totalMilliseconds' => 1234.5, 'projects' => [['id' => '.', 'files' => 41]]],
            'diagnostics' => [],
            'baseline' => ['path' => null, 'mode' => 'none', 'strict' => false, 'stale' => []],
            'summary' => ['diagnostics' => 0, 'active' => 0, 'matched' => 0, 'stale' => 0, 'blocking' => 0],
            'errors' => [],
        ], $overrides), \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function project(array $overrides = []): array
    {
        return array_replace([
            'id' => '.',
            'environment' => 'prod',
            'analysis' => ['mode' => 'runtime', 'reason' => null],
            'source' => ['state' => 'ready'],
            'runtime' => ['state' => 'ready', 'stage' => 'container'],
            'complete' => true,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function diagnostic(string $file, string $code, array $overrides = []): array
    {
        return array_replace([
            'project' => '.',
            'path' => $file,
            'workspacePath' => $file,
            'range' => self::range(3, 4, 3, 9),
            'severity' => 'error',
            'code' => $code,
            'source' => 'symfony',
            'message' => 'The service does not exist.',
            'baseline' => 'active',
            'provenance' => ['feature' => 'service', 'provider' => 'dependency-injection', 'environment' => 'prod', 'analysisMode' => 'runtime'],
        ], $overrides);
    }

    /** @return array<string, array<string, int>> */
    private static function range(int $startLine, int $startCharacter, int $endLine, int $endCharacter): array
    {
        return [
            'start' => ['line' => $startLine, 'character' => $startCharacter],
            'end' => ['line' => $endLine, 'character' => $endCharacter],
        ];
    }
}
