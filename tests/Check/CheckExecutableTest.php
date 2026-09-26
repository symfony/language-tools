<?php

namespace Symfony\Lsp\Tests\Check;

use Amp\Process\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Check\CheckCommand;
use Symfony\Lsp\Runtime\PartialRuntimeMetadataException;
use Symfony\Lsp\Runtime\UnsupportedSymfonyVersionException;
use Symfony\Lsp\Server\ServerVersion;
use Symfony\Lsp\Tests\Support\TestWorkspace;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\Future\await;

/**
 * @phpstan-type GitLabIssue array{description: string, check_name: string, fingerprint: string, severity: string, location: array{path: string, lines: array{begin: int}}}
 * @phpstan-type SarifNotification array{descriptor: array{id: string}}
 * @phpstan-type SarifInvocation array{executionSuccessful: bool, exitCode: int, toolConfigurationNotifications?: list<SarifNotification>}
 * @phpstan-type SarifResult array{ruleId: string}
 * @phpstan-type SarifRun array{tool: array{driver: array{rules: list<array{id: string}>}}, invocations: list<SarifInvocation>, results: list<SarifResult>}
 * @phpstan-type SarifReport array{runs: list<SarifRun>}
 * @phpstan-type CheckReport array{
 *     complete: bool,
 *     projects: list<array{environment: string, analysis: array{mode: string, reason: string|null}, runtime: array{state: string}, complete: bool}>,
 *     profile?: array{totalMilliseconds: int|float, phasesMilliseconds: array<string, int|float|null>, projects: list<array{files: int, phasesMilliseconds: array<string, int|float|null>, diagnosticProvidersMilliseconds: array<string, int|float>, slowestFilesMilliseconds: array<string, int|float>}>},
 *     diagnostics: list<array{code: string, path: string, baseline: string}>,
 *     baseline: array{stale: list<array<string, mixed>>},
 *     summary: array{active: int, matched: int, blocking: int, stale: int},
 *     errors: list<array{category: string, message: string, cause?: array{class: string, message: string, sections?: list<array{section: string, chain: list<array{class: string, message: string, origin?: string, frames: list<string>}>}>}}>
 * }
 */
final class CheckExecutableTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-check-');
        $this->workspace->mkdir('config');
        $this->workspace->write('composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
        $this->workspace->write('config/services.yaml', "parameters:\n    broken: '%env(APP_SECRET%'\n");
        $this->workspace->write('releases.json', json_encode([
            'supported_versions' => ['8.0'],
        ], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
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

        $marker = $this->workspace->path('symfony-cli-command.json');
        $symfonyCli = $this->workspace->path('symfony');
        file_put_contents($symfonyCli, "#!/usr/bin/env php\n<?php\nfile_put_contents(".var_export($marker, true).", json_encode(array_slice(\$argv, 1), JSON_THROW_ON_ERROR));\nexit(1);\n");
        chmod($symfonyCli, 0700);

        $result = $this->execute(
            ['check', '--format=json', '--workspace='.$this->workspace->rootPath],
            ['SYMFONY_LSP_SYMFONY_CLI' => $symfonyCli],
        );
        /** @var list<string> $command */
        $command = json_decode((string) file_get_contents($marker), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $result['exitCode']);
        self::assertSame('php', $command[0]);
        self::assertStringEndsWith('/bridge.php', str_replace('\\', '/', $command[1]));
    }

    public function testSkipsDependencyInjectionDiagnosticsFromInactiveEnvironmentFiles(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('The source executable integration requires Unix executable scripts.');
        }

        $this->workspace->write('config/services_test.yaml', <<<'YAML'
            services:
                app.test_client:
                    arguments:
                        $parameters: '%test.client.parameters%'
            YAML);
        $symfonyCli = $this->workspace->path('environment-bridge');
        file_put_contents($symfonyCli, <<<'PHP'
            #!/usr/bin/env php
            <?php

            $environment = 'dev';
            foreach ($argv as $argument) {
                if (str_starts_with($argument, '--environment=')) {
                    $environment = substr($argument, strlen('--environment='));
                }
            }
            fwrite(STDOUT, json_encode([
                'schemaVersion' => 1,
                'project' => ['environment' => $environment],
                'configurationValidation' => ['status' => 'valid'],
                'sections' => [
                    'container' => [
                        'complete' => true,
                        'servicesComplete' => false,
                        'parametersComplete' => true,
                        'items' => [],
                        'parameters' => 'test' === $environment ? [['name' => 'test.client.parameters']] : [],
                    ],
                ],
                'errors' => [],
            ], JSON_THROW_ON_ERROR)."\n");
            PHP);
        chmod($symfonyCli, 0700);
        $environment = ['SYMFONY_LSP_SYMFONY_CLI' => $symfonyCli];

        $dev = $this->execute([
            'check',
            '--format=json',
            '--workspace='.$this->workspace->rootPath,
            'config/services_test.yaml',
        ], $environment);
        $test = $this->execute([
            'check',
            '--format=json',
            '--workspace='.$this->workspace->rootPath,
            '--environment=test',
            'config/services_test.yaml',
        ], $environment);

        self::assertSame(CheckCommand::EXIT_SUCCESS, $dev['exitCode'], $dev['stderr']);
        self::assertSame([], $this->decodeReport($dev['stdout'])['diagnostics']);
        self::assertSame(CheckCommand::EXIT_SUCCESS, $test['exitCode'], $test['stderr']);
        self::assertSame([], $this->decodeReport($test['stdout'])['diagnostics']);
    }

    public function testSkipsSecurityAndEnvironmentDiagnosticsFromInactiveEnvironmentFiles(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('The source executable integration requires Unix executable scripts.');
        }

        $this->workspace->mkdir('config/packages/test');
        $this->workspace->write('config/packages/test/security.yaml', <<<'YAML'
            security:
                firewalls:
                    main:
                        provider: php_test_users
                    api:
                        provider: missing_users
            YAML);
        $this->workspace->write('config/packages/test/framework_extra.yaml', <<<'YAML'
            framework:
                default_locale: '%env(test_only_processor:APP_LOCALE)%'
            YAML);
        $symfonyCli = $this->workspace->path('environment-bridge');
        file_put_contents($symfonyCli, <<<'PHP'
            #!/usr/bin/env php
            <?php

            $environment = 'dev';
            foreach ($argv as $argument) {
                if (str_starts_with($argument, '--environment=')) {
                    $environment = substr($argument, strlen('--environment='));
                }
            }
            $test = 'test' === $environment;
            fwrite(STDOUT, json_encode([
                'schemaVersion' => 1,
                'project' => ['environment' => $environment],
                'configurationValidation' => ['status' => 'valid'],
                'sections' => [
                    'security' => [
                        'complete' => true,
                        'firewalls' => [],
                        'providers' => $test ? [['name' => 'php_test_users', 'type' => 'memory']] : [],
                    ],
                    'environment' => [
                        'complete' => true,
                        'processors' => $test ? [['name' => 'test_only_processor', 'type' => 'string']] : [],
                    ],
                ],
                'errors' => [],
            ], JSON_THROW_ON_ERROR)."\n");
            PHP);
        chmod($symfonyCli, 0700);
        $environment = ['SYMFONY_LSP_SYMFONY_CLI' => $symfonyCli];
        $selectors = ['config/packages/test/security.yaml', 'config/packages/test/framework_extra.yaml'];

        $dev = $this->execute([
            'check',
            '--format=json',
            '--workspace='.$this->workspace->rootPath,
            ...$selectors,
        ], $environment);
        $test = $this->execute([
            'check',
            '--format=json',
            '--workspace='.$this->workspace->rootPath,
            '--environment=test',
            ...$selectors,
        ], $environment);

        self::assertSame(CheckCommand::EXIT_SUCCESS, $dev['exitCode'], $dev['stderr']);
        self::assertSame([], $this->decodeReport($dev['stdout'])['diagnostics']);
        self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $test['exitCode'], $test['stderr']);
        $diagnostics = $this->decodeReport($test['stdout'])['diagnostics'];
        self::assertSame(['security.unknown_provider'], array_column($diagnostics, 'code'));
        self::assertSame('config/packages/test/security.yaml', $diagnostics[0]['path']);
    }

    public function testReportsSavedFileDiagnosticsWithoutAnLspClient(): void
    {
        $result = $this->execute(['check', '--source-only', '--format=json', '--workspace='.$this->workspace->rootPath, 'config/**/*.yaml']);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $result['exitCode'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        self::assertTrue($report['complete']);
        self::assertArrayNotHasKey('profile', $report);
        self::assertSame('source-only', $report['projects'][0]['analysis']['mode']);
        self::assertSame('runtime-indexing-disabled', $report['projects'][0]['analysis']['reason']);
        self::assertSame('env.malformed_chain', $report['diagnostics'][0]['code']);
        self::assertSame('config/services.yaml', $report['diagnostics'][0]['path']);
        self::assertSame(1, $report['summary']['blocking']);
    }

    public function testNumbersRepeatedIdenticalDiagnosticsOnceForBaselinesAndReports(): void
    {
        file_put_contents(
            $this->workspace->path('config/services.yaml'),
            "parameters:\n    first: '%env(APP_SECRET%'\n    second: '%env(APP_SECRET%'\n",
        );

        $gitLab = $this->execute([
            'check',
            '--source-only',
            '--format=gitlab',
            '--workspace='.$this->workspace->rootPath,
            'config/services.yaml',
        ]);
        /** @var list<GitLabIssue> $issues */
        $issues = json_decode($gitLab['stdout'], true, flags: \JSON_THROW_ON_ERROR);

        $generated = $this->execute([
            'check',
            '--source-only',
            '--format=json',
            '--workspace='.$this->workspace->rootPath,
            '--baseline=baseline.json',
            '--generate-baseline',
            'config/services.yaml',
        ]);
        /** @var array{diagnostics: list<array{fingerprint: string, occurrence: int}>} $baseline */
        $baseline = json_decode((string) file_get_contents($this->workspace->path('baseline.json')), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(CheckCommand::EXIT_SUCCESS, $generated['exitCode'], $generated['stderr']);
        self::assertSame([1, 2], array_column($baseline['diagnostics'], 'occurrence'));
        self::assertCount(1, array_unique(array_column($baseline['diagnostics'], 'fingerprint')));
        self::assertCount(2, $issues);
        self::assertNotSame($issues[0]['fingerprint'], $issues[1]['fingerprint']);
    }

    public function testProfilesCheckerPhasesProjectsProvidersAndFiles(): void
    {
        $result = $this->execute([
            'check',
            '--profile',
            '--source-only',
            '--format=json',
            '--workspace='.$this->workspace->rootPath,
            'config/services.yaml',
        ]);
        $report = $this->decodeReport($result['stdout']);
        $profile = $report['profile'] ?? null;

        self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $result['exitCode'], $result['stderr']);
        self::assertIsArray($profile);
        self::assertSame([
            'startup',
            'configuration',
            'projectDiscovery',
            'fileSelection',
            'projectAnalysis',
            'diagnostics',
            'resultProcessing',
        ], array_keys($profile['phasesMilliseconds']));
        self::assertGreaterThanOrEqual(0.0, (float) $profile['totalMilliseconds']);
        self::assertSame(1, $profile['projects'][0]['files']);
        self::assertSame([
            'sourceIndex',
            'filePreparation',
            'runtimeIndex',
            'diagnostics',
        ], array_keys($profile['projects'][0]['phasesMilliseconds']));
        self::assertNull($profile['projects'][0]['phasesMilliseconds']['runtimeIndex']);
        self::assertArrayHasKey('environment', $profile['projects'][0]['diagnosticProvidersMilliseconds']);
        self::assertSame(['config/services.yaml'], array_keys($profile['projects'][0]['slowestFilesMilliseconds']));
        self::assertStringContainsString("Timing profile:\n", $result['stderr']);
        self::assertStringContainsString('Diagnostic providers:', $result['stderr']);
        self::assertStringContainsString('Slowest diagnostic files:', $result['stderr']);

        $human = $this->execute([
            'check',
            '--profile',
            '--source-only',
            '--workspace='.$this->workspace->rootPath,
            'config/services.yaml',
        ]);
        self::assertStringNotContainsString('Timing profile:', $human['stdout']);
        self::assertStringContainsString('Timing profile:', $human['stderr']);
    }

    public function testRendersSarifForDiagnosticsAndCodeLists(): void
    {
        $result = $this->execute(['check', '--source-only', '--format=sarif', '--workspace='.$this->workspace->rootPath, 'config/services.yaml']);
        /** @var SarifReport $sarif */
        $sarif = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        $codes = $this->execute(['check', '--format=sarif', '--list-codes']);
        /** @var SarifReport $codeSarif */
        $codeSarif = json_decode($codes['stdout'], true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $result['exitCode'], $result['stderr']);
        self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $sarif['runs'][0]['invocations'][0]['exitCode']);
        self::assertSame('env.malformed_chain', $sarif['runs'][0]['results'][0]['ruleId']);
        self::assertSame(CheckCommand::EXIT_SUCCESS, $codes['exitCode'], $codes['stderr']);
        self::assertSame([], $codeSarif['runs'][0]['results']);
        self::assertNotSame([], $codeSarif['runs'][0]['tool']['driver']['rules']);
    }

    public function testRendersGitLabCodeQualityReports(): void
    {
        $result = $this->execute(['check', '--source-only', '--format=gitlab', '--workspace='.$this->workspace->rootPath, 'config/services.yaml']);
        /** @var list<GitLabIssue> $report */
        $report = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $result['exitCode'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        self::assertSame('env.malformed_chain', $report[0]['check_name']);
    }

    public function testExcludesConfiguredPathsUnlessTheyAreExplicitlySelected(): void
    {
        $this->workspace->write('.symfony-lsp.json', json_encode([
            'version' => 1,
            'excludePaths' => ['config/**'],
        ], \JSON_THROW_ON_ERROR));

        $default = $this->execute(['check', '--source-only', '--format=json', '--workspace='.$this->workspace->rootPath]);
        $defaultReport = $this->decodeReport($default['stdout']);
        $explicit = $this->execute(['check', '--source-only', '--format=json', '--workspace='.$this->workspace->rootPath, 'config/services.yaml']);
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
            '--workspace='.$this->workspace->rootPath,
            '--fail-on=config.deprecated_key',
        ]);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame('env.malformed_chain', $report['diagnostics'][0]['code']);
        self::assertSame(0, $report['summary']['blocking']);
    }

    public function testCommandLineSettingsOverrideCheckedInSettings(): void
    {
        $this->workspace->write('.symfony-lsp.json', json_encode([
            'version' => 1,
            'environment' => 'prod',
            'runtimeIndexing' => false,
        ], \JSON_THROW_ON_ERROR));

        $result = $this->execute([
            'check',
            '--format=json',
            '--workspace='.$this->workspace->rootPath,
            '--environment=test',
        ]);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $result['exitCode'], $result['stderr']);
        self::assertSame('test', $report['projects'][0]['environment']);
        self::assertSame('source-only', $report['projects'][0]['analysis']['mode']);
    }

    public function testReportsVendorConfigurationFailuresAndIncompleteAnalysis(): void
    {
        $this->workspace->write('config/services.yaml', "parameters:\n    valid: value\n");
        $this->workspace->mkdir('vendor');
        $this->workspace->write('vendor/autoload.php', <<<'PHP'
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

        $result = $this->execute(['check', '--format=json', '--workspace='.$this->workspace->rootPath, 'config/services.yaml']);
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
        $this->workspace->write('config/services.yaml', "parameters:\n    valid: value\n");
        $this->workspace->mkdir('vendor');
        $this->workspace->write('vendor/autoload.php', <<<'PHP'
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

        $result = $this->execute(['check', '--format=json', '--workspace='.$this->workspace->rootPath, 'config/services.yaml']);
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
        $this->workspace->write('composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^5.4'],
        ], \JSON_THROW_ON_ERROR));
        $this->workspace->mkdir('vendor');
        $this->workspace->write('vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '5.4.45'; }
            }
            PHP);

        $result = $this->execute(['check', '--format=json', '--workspace='.$this->workspace->rootPath]);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $result['exitCode']);
        self::assertFalse($report['complete']);
        self::assertSame('Symfony 5.4 is not supported by Symfony Language Tools.', $report['errors'][0]['message']);
        self::assertSame(UnsupportedSymfonyVersionException::class, $report['errors'][0]['cause']['class'] ?? null);
    }

    public function testKeepsDiagnosticsBackedByHealthyRuntimeSections(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('The source executable integration requires Unix executable scripts.');
        }

        $this->workspace->mkdir('src');
        $this->workspace->write('src/ArticleController.php', <<<'PHP'
            <?php
            namespace App\Controller;

            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

            final class ArticleController extends AbstractController
            {
                public function show(): void
                {
                    $this->generateUrl('article_show');
                }
            }
            PHP);
        $snapshot = [
            'schemaVersion' => 1,
            'project' => ['environment' => 'dev'],
            'configurationValidation' => ['status' => 'valid'],
            'sections' => [
                'routes' => [
                    'complete' => true,
                    'items' => [[
                        'name' => 'article_show',
                        'path' => '/article/{id}',
                    ]],
                ],
            ],
            'errors' => [[
                'section' => 'twig',
                'message' => 'CANARY_RUNTIME_SECTION_ERROR',
                'cause' => ['chain' => [[
                    'class' => \RuntimeException::class,
                    'message' => 'CANARY_TWIG_FAILURE',
                    'origin' => 'src/TwigExtension.php:24',
                    'frames' => [
                        'App\\TwigExtension->getFunctions (src/TwigExtension.php:20) '.str_repeat('a', 120),
                        'Twig\\ExtensionSet->initExtension (vendor/twig/twig/src/ExtensionSet.php:454) '.str_repeat('b', 120),
                        'Twig\\Environment->getFunctions (vendor/twig/twig/src/Environment.php:777) '.str_repeat('c', 120),
                        'Symfony\\Bridge\\Twig\\Command\\DebugCommand->execute (vendor/symfony/twig-bridge/Command/DebugCommand.php:123) '.str_repeat('d', 120),
                        'App\\FinalFrame->run (src/FinalFrame.php:99)',
                    ],
                ]]],
            ]],
        ];
        $symfonyCli = $this->workspace->path('partial-runtime');
        file_put_contents($symfonyCli, "#!/usr/bin/env php\n<?php\nfwrite(STDOUT, json_encode(".var_export($snapshot, true).", JSON_THROW_ON_ERROR).\"\\n\");\n");
        chmod($symfonyCli, 0700);

        $result = $this->execute(
            ['check', '--format=json', '--workspace='.$this->workspace->rootPath, 'src/ArticleController.php'],
            ['SYMFONY_LSP_SYMFONY_CLI' => $symfonyCli],
        );
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $result['exitCode'], $result['stderr']);
        self::assertFalse($report['complete']);
        self::assertFalse($report['projects'][0]['complete']);
        self::assertSame('partial', $report['projects'][0]['runtime']['state']);
        self::assertSame('route.missing_parameters', $report['diagnostics'][0]['code']);
        self::assertSame('The project bridge could not load runtime metadata: twig.', $report['errors'][0]['message']);
        self::assertSame(PartialRuntimeMetadataException::class, $report['errors'][0]['cause']['class'] ?? null);
        self::assertStringNotContainsString('CANARY_TWIG_FAILURE', $report['errors'][0]['cause']['message']);
        self::assertArrayNotHasKey('sections', $report['errors'][0]['cause']);
        self::assertSame(1, $report['summary']['blocking']);

        $verbose = $this->execute(
            ['check', '-vvv', '--format=json', '--workspace='.$this->workspace->rootPath, 'src/ArticleController.php'],
            ['SYMFONY_LSP_SYMFONY_CLI' => $symfonyCli],
        );
        $verboseReport = $this->decodeReport($verbose['stdout']);

        self::assertSame(
            'The project bridge could not load runtime metadata: twig.',
            $verboseReport['errors'][0]['cause']['message'] ?? null,
        );
        $section = $verboseReport['errors'][0]['cause']['sections'][0] ?? [];
        self::assertSame('twig', $section['section'] ?? null);
        self::assertSame('RuntimeException', $section['chain'][0]['class'] ?? null);
        self::assertSame('CANARY_TWIG_FAILURE', $section['chain'][0]['message']);
        self::assertSame('src/TwigExtension.php:24', $section['chain'][0]['origin'] ?? null);
        self::assertStringStartsWith(
            'App\\TwigExtension->getFunctions (src/TwigExtension.php:20)',
            $section['chain'][0]['frames'][0] ?? '',
        );
        self::assertSame('App\\FinalFrame->run (src/FinalFrame.php:99)', $section['chain'][0]['frames'][4] ?? null);
    }

    public function testMatchesBaselinesWithoutUpdatingOrEnforcingThemDuringPartialRuntimeAnalysis(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('The source executable integration requires Unix executable scripts.');
        }

        $this->workspace->mkdir('src');
        $this->workspace->write('src/ArticleController.php', <<<'PHP'
            <?php
            namespace App\Controller;

            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

            final class ArticleController extends AbstractController
            {
                public function show(): void
                {
                    $this->generateUrl('article_show');
                }
            }
            PHP);
        $snapshot = [
            'schemaVersion' => 1,
            'project' => ['environment' => 'dev'],
            'configurationValidation' => ['status' => 'valid'],
            'sections' => [
                'routes' => [
                    'complete' => true,
                    'items' => [[
                        'name' => 'article_show',
                        'path' => '/article/{id}',
                    ]],
                ],
            ],
        ];
        $symfonyCli = $this->workspace->path('partial-runtime-baseline');
        file_put_contents($symfonyCli, "#!/usr/bin/env php\n<?php\nfwrite(STDOUT, json_encode(".var_export($snapshot, true).", JSON_THROW_ON_ERROR).\"\\n\");\n");
        chmod($symfonyCli, 0700);
        $environment = ['SYMFONY_LSP_SYMFONY_CLI' => $symfonyCli];
        $baselinePath = $this->workspace->path('baseline.json');

        $created = $this->execute([
            'check',
            '--format=json',
            '--workspace='.$this->workspace->rootPath,
            '--baseline=baseline.json',
            '--generate-baseline',
            'src/ArticleController.php',
        ], $environment);
        self::assertSame(CheckCommand::EXIT_SUCCESS, $created['exitCode'], $created['stderr']);
        /** @var array{version: int, diagnostics: list<array<string, mixed>>} $baseline */
        $baseline = json_decode((string) file_get_contents($baselinePath), true, flags: \JSON_THROW_ON_ERROR);
        $staleEntry = $baseline['diagnostics'][0];
        $staleEntry['occurrence'] = 2;
        $baseline['diagnostics'][] = $staleEntry;
        file_put_contents($baselinePath, json_encode($baseline, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
        $baselineContents = (string) file_get_contents($baselinePath);

        $snapshot['errors'] = [[
            'section' => 'twig',
            'message' => 'Runtime section failed.',
        ]];
        file_put_contents($symfonyCli, "#!/usr/bin/env php\n<?php\nfwrite(STDOUT, json_encode(".var_export($snapshot, true).", JSON_THROW_ON_ERROR).\"\\n\");\n");

        $partial = $this->execute([
            'check',
            '--format=json',
            '--workspace='.$this->workspace->rootPath,
            '--baseline=baseline.json',
            '--strict-baseline',
            'src/ArticleController.php',
        ], $environment);
        $report = $this->decodeReport($partial['stdout']);

        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $partial['exitCode'], $partial['stderr']);
        self::assertSame('matched', $report['diagnostics'][0]['baseline']);
        self::assertSame(0, $report['summary']['active']);
        self::assertSame(1, $report['summary']['matched']);
        self::assertSame(0, $report['summary']['stale']);
        self::assertSame(0, $report['summary']['blocking']);
        self::assertSame([], $report['baseline']['stale']);

        $refreshed = $this->execute([
            'check',
            '--format=json',
            '--workspace='.$this->workspace->rootPath,
            '--baseline=baseline.json',
            '--refresh-baseline',
            'src/ArticleController.php',
        ], $environment);
        $refreshedReport = $this->decodeReport($refreshed['stdout']);
        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $refreshed['exitCode'], $refreshed['stderr']);
        self::assertSame('matched', $refreshedReport['diagnostics'][0]['baseline']);
        self::assertSame($baselineContents, file_get_contents($baselinePath));

        $generatedPath = $this->workspace->path('partial-baseline.json');
        $generated = $this->execute([
            'check',
            '--format=json',
            '--workspace='.$this->workspace->rootPath,
            '--baseline=partial-baseline.json',
            '--generate-baseline',
            'src/ArticleController.php',
        ], $environment);
        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $generated['exitCode'], $generated['stderr']);
        self::assertFileDoesNotExist($generatedPath);
    }

    public function testDoesNotReportACleanResultWhenRuntimeIndexingFails(): void
    {
        $result = $this->execute(['check', '--format=json', '--workspace='.$this->workspace->rootPath]);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $result['exitCode']);
        self::assertFalse($report['complete']);
        self::assertSame('operational', $report['errors'][0]['category']);
        self::assertIsArray($report['errors'][0]['cause'] ?? null);
        self::assertStringNotContainsString('APP_SECRET', $result['stdout'].$result['stderr']);
    }

    public function testDoesNotOverlayExplicitExcludedFilesAfterOperationalRuntimeFailure(): void
    {
        $this->workspace->write('.symfony-lsp.json', json_encode([
            'version' => 1,
            'excludePaths' => ['config/**'],
        ], \JSON_THROW_ON_ERROR));
        $this->workspace->mkdir('vendor');
        $this->workspace->write('vendor/autoload.php', <<<'PHP'
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

        $result = $this->execute(['check', '--format=json', '--workspace='.$this->workspace->rootPath, 'config/services.yaml']);
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
        $baseline = $this->workspace->path('baseline.json');
        $create = $this->execute([
            'check',
            '--source-only',
            '--format=json',
            '--workspace='.$this->workspace->rootPath,
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
            '--workspace='.$this->workspace->rootPath,
            '--baseline=baseline.json',
        ]);
        self::assertSame(0, $matched['exitCode'], $matched['stderr']);
        self::assertSame($baselineHash, hash_file('sha256', $baseline));

        $this->workspace->write('config/services.yaml', "parameters:\n    # @symfony-lsp-ignore env.malformed_chain (intentional malformed expression)\n    broken: '%env(APP_SECRET%'\n");
        $strict = $this->execute([
            'check',
            '--source-only',
            '--format=json',
            '--workspace='.$this->workspace->rootPath,
            '--baseline=baseline.json',
            '--strict-baseline',
        ]);
        $report = $this->decodeReport($strict['stdout']);

        self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $strict['exitCode'], $strict['stderr']);
        self::assertSame([], $report['diagnostics']);
        self::assertSame(1, $report['summary']['stale']);
        self::assertSame(1, $report['summary']['blocking']);
    }

    public function testRejectsUnreadableApplicationDirectories(): void
    {
        $directory = $this->workspace->path('blocked');
        mkdir($directory);
        chmod($directory, 0000);
        try {
            if (is_readable($directory)) {
                self::markTestSkipped('The platform cannot make the directory unreadable.');
            }

            $result = $this->execute(['check', '--source-only', '--format=json', '--workspace='.$this->workspace->rootPath]);
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
        $external = $this->workspace->rootPath.'-external.php';
        file_put_contents($external, '<?php');
        $this->workspace->mkdir('fixtures');
        try {
            if (!@symlink($external, $this->workspace->path('fixtures/external.php'))) {
                self::markTestSkipped('The platform cannot create file symlinks.');
            }
            $this->workspace->write('.symfony-lsp.json', json_encode([
                'version' => 1,
                'excludePaths' => ['fixtures/**'],
            ], \JSON_THROW_ON_ERROR));

            $result = $this->execute(['check', '--source-only', '--format=json', '--workspace='.$this->workspace->rootPath, 'config/services.yaml']);
            $report = $this->decodeReport($result['stdout']);

            self::assertSame(CheckCommand::EXIT_DIAGNOSTICS, $result['exitCode'], $result['stderr']);
            self::assertSame('env.malformed_chain', $report['diagnostics'][0]['code']);
        } finally {
            @unlink($external);
        }
    }

    public function testRejectsApplicationSymlinksThatResolveOutsideTheProject(): void
    {
        $external = $this->workspace->rootPath.'-external.php';
        file_put_contents($external, '<?php');
        try {
            if (!@symlink($external, $this->workspace->path('config/external.php'))) {
                self::markTestSkipped('The platform cannot create file symlinks.');
            }

            $result = $this->execute(['check', '--source-only', '--format=json', '--workspace='.$this->workspace->rootPath]);
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
            '--profile',
            '--source-only',
            '--format=json',
            '--workspace='.$this->workspace->rootPath,
            '--timeout=0.000001',
        ]);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_OPERATIONAL, $result['exitCode']);
        self::assertFalse($report['complete']);
        self::assertIsArray($report['profile'] ?? null);
        self::assertStringContainsString('timed out', $result['stderr']);
        self::assertStringContainsString('Timing profile:', $result['stderr']);
    }

    public function testCancelsDuringSourceRefreshBeforeDiagnostics(): void
    {
        if ('Windows' === \PHP_OS_FAMILY || !\function_exists('pcntl_signal')) {
            self::markTestSkipped('The source executable integration requires Unix signals.');
        }
        for ($index = 0; $index < 256; ++$index) {
            file_put_contents(\sprintf('%s/config/service_%03d.yaml', $this->workspace->rootPath, $index), "parameters:\n    value: '%env(APP_SECRET%\'\n");
        }

        $process = $this->start(['check', '--source-only', '--format=json', '--workspace='.$this->workspace->rootPath]);
        $deadline = microtime(true) + 10;
        do {
            $indexFiles = glob($this->workspace->path('var/symfony-lsp/*/index/source.jsonl.tmp')) ?: [];
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
            '--workspace='.$this->workspace->rootPath,
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
            '--workspace='.$this->workspace->rootPath,
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
        $result = $this->execute(['check', '--format=sarif', '--workspace='.$this->workspace->rootPath, 'missing.php']);
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
        $result = $this->execute(['check', '--format=json', '--workspace='.$this->workspace->rootPath, 'missing.php']);
        $report = $this->decodeReport($result['stdout']);

        self::assertSame(CheckCommand::EXIT_INVOCATION, $result['exitCode']);
        self::assertFalse($report['complete']);
        self::assertSame('invocation', $report['errors'][0]['category']);
        self::assertStringContainsString('does not exist', $result['stderr']);
    }

    #[DataProvider('unknownCheckFlags')]
    public function testReportsUnknownCheckFlags(string $flag): void
    {
        $result = $this->execute(['check', $flag]);

        self::assertSame(CheckCommand::EXIT_INVOCATION, $result['exitCode']);
        self::assertSame(\sprintf('Unknown check option "%s".%s', $flag, \PHP_EOL), $result['stderr']);
    }

    #[DataProvider('verboseAliases')]
    public function testAcceptsVerboseAliasesWhenOptionParsingFails(string $alias): void
    {
        $result = $this->execute(['check', $alias, '--unknown']);

        self::assertSame(CheckCommand::EXIT_INVOCATION, $result['exitCode']);
        self::assertSame('Unknown check option "--unknown".'.\PHP_EOL, $result['stderr']);
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
        } elseif ('gitlab' === $format) {
            self::assertSame([], json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR));
        } else {
            /** @var SarifReport $report */
            $report = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('symfony.check.invocation', $report['runs'][0]['invocations'][0]['toolConfigurationNotifications'][0]['descriptor']['id'] ?? null);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unknownCheckFlags(): iterable
    {
        yield 'long flag' => ['--unknown'];
        yield 'short flag' => ['-x'];
        yield 'unsupported verbosity flag' => ['-vvvv'];
    }

    /** @return iterable<string, array{string}> */
    public static function verboseAliases(): iterable
    {
        yield '-v' => ['-v'];
        yield '-vv' => ['-vv'];
        yield '-vvv' => ['-vvv'];
    }

    /** @return iterable<string, array{string}> */
    public static function machineFormats(): iterable
    {
        yield 'JSON' => ['json'];
        yield 'GitLab' => ['gitlab'];
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
                'SYMFONY_LSP_RELEASE_METADATA_URL' => $this->workspace->path('releases.json'),
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
