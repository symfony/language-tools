<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BridgeTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory.'/vendor', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->temporaryDirectory.'/bin/console');
        @rmdir($this->temporaryDirectory.'/bin');
        @unlink($this->temporaryDirectory.'/vendor/autoload.php');
        @rmdir($this->temporaryDirectory.'/vendor');
        @rmdir($this->temporaryDirectory);
    }

    #[DataProvider('supportedVersionProvider')]
    public function testReportsSupportedProjectMetadata(string $version, string $branch): void
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
        self::assertSame($branch, $result['project']['symfonyBranch']);
        self::assertSame('test', $result['project']['environment']);
        self::assertFalse($result['project']['debug']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function supportedVersionProvider(): iterable
    {
        yield '6.4' => ['6.4.30', '6.4'];
        yield '7.4' => ['7.4.8', '7.4'];
        yield '8.0' => ['8.0.6', '8.0'];
        yield '8.1' => ['8.1.0-RC1', '8.1'];
    }

    public function testNormalizesStructuredRouteOutput(): void
    {
        $this->writeRouteApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=routes 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame([], $result['errors']);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['routes'] ?? null);
        self::assertSame([
            [
                'name' => 'article_legacy',
                'path' => null,
                'methods' => [],
                'schemes' => [],
                'host' => null,
                'controller' => null,
                'defaults' => [],
                'requirements' => [],
                'alias' => 'article_show',
            ],
            [
                'name' => 'article_show',
                'path' => '/article/{id}',
                'methods' => ['GET', 'HEAD'],
                'schemes' => [],
                'host' => null,
                'controller' => 'App\\Controller\\ArticleController::show',
                'defaults' => ['_controller'],
                'requirements' => ['id' => '\\d+'],
                'alias' => null,
            ],
            [
                'name' => 'homepage',
                'path' => '/',
                'methods' => [],
                'schemes' => ['https'],
                'host' => 'example.com',
                'controller' => null,
                'defaults' => [],
                'requirements' => [],
                'alias' => null,
            ],
        ], $result['sections']['routes']['items']);
        self::assertTrue($result['sections']['routes']['complete']);
    }

    public function testNormalizesContainerMetadataWithoutExportingParameterValues(): void
    {
        $this->writeContainerApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=container 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        self::assertStringNotContainsString('CANARY_SECRET_VALUE', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame([], $result['errors']);
        self::assertIsArray($result['sections']);
        self::assertIsArray($result['sections']['container']);
        self::assertSame([
            [
                'id' => 'app.mailer',
                'class' => 'App\\Mailer',
                'alias' => null,
                'public' => false,
                'lazy' => true,
                'deprecation' => 'Use app.new_mailer instead.',
                'tags' => ['kernel.reset', 'monolog.logger'],
                'decorates' => 'mailer',
                'autowiringTypes' => ['App\\MailerInterface'],
            ],
            [
                'id' => 'mailer',
                'class' => null,
                'alias' => 'app.mailer',
                'public' => true,
                'lazy' => null,
                'deprecation' => null,
                'tags' => [],
                'decorates' => null,
                'autowiringTypes' => [],
            ],
        ], $result['sections']['container']['items']);
        self::assertSame([
            ['name' => 'app.api_key', 'deprecation' => null],
            ['name' => 'app.storage_dir', 'deprecation' => null],
        ], $result['sections']['container']['parameters']);
    }

    public function testRejectsUnsupportedSymfonyBranches(): void
    {
        $this->writeAutoloader('7.3.9');

        exec(\sprintf(
            '%s %s --project=%s 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        self::assertSame(1, $exitCode);
        self::assertSame('Symfony FrameworkBundle 7.3.9 is not supported.', implode("\n", $output));
    }

    private function writeRouteApplication(): void
    {
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string
                {
                    return '8.0.6';
                }
            }
            namespace Symfony\Component\Console\Input;
            final class ArrayInput
            {
                public function __construct(public array $arguments) {}
            }
            namespace Symfony\Component\Console\Output;
            final class BufferedOutput
            {
                private string $contents = '';
                public function write(string $contents): void { $this->contents .= $contents; }
                public function fetch(): string { return $this->contents; }
            }
            namespace App;
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function shutdown(): void {}
            }
            namespace Symfony\Bundle\FrameworkBundle\Console;
            final class Application
            {
                public function __construct(object $kernel) {}
                public function setAutoExit(bool $autoExit): void {}
                public function run(object $input, object $output): int
                {
                    $output->write(json_encode([
                        'article_legacy' => [
                            'alias' => 'article_show',
                            'method' => 'ANY',
                            'scheme' => 'ANY',
                            'host' => 'ANY',
                            'defaults' => [],
                        ],
                        'homepage' => [
                            'path' => '/',
                            'method' => 'ANY',
                            'scheme' => 'https',
                            'host' => 'example.com',
                            'defaults' => [],
                        ],
                        'article_show' => [
                            'path' => '/article/{id}',
                            'method' => 'GET|HEAD',
                            'scheme' => 'ANY',
                            'host' => 'ANY',
                            'defaults' => ['_controller' => 'App\\Controller\\ArticleController::show'],
                            'requirements' => ['id' => '\\d+'],
                        ],
                    ], JSON_THROW_ON_ERROR));

                    return 0;
                }
            }
            PHP);
    }

    private function writeContainerApplication(): void
    {
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string
                {
                    return '8.0.6';
                }
            }
            namespace Symfony\Component\Console\Input;
            final class ArrayInput
            {
                public function __construct(public array $arguments) {}
            }
            namespace Symfony\Component\Console\Output;
            final class BufferedOutput
            {
                private string $contents = '';
                public function write(string $contents): void { $this->contents .= $contents; }
                public function fetch(): string { return $this->contents; }
            }
            namespace App;
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function shutdown(): void {}
            }
            namespace Symfony\Bundle\FrameworkBundle\Console;
            final class Application
            {
                public function __construct(object $kernel) {}
                public function setAutoExit(bool $autoExit): void {}
                public function run(object $input, object $output): int
                {
                    if (isset($input->arguments['--show-hidden'])) {
                        $result = [
                            'definitions' => [
                                'app.mailer' => [
                                    'class' => 'App\\Mailer',
                                    'public' => false,
                                    'lazy' => true,
                                    'deprecated' => ['message' => 'Use app.new_mailer instead.'],
                                    'tags' => [
                                        'monolog.logger' => [['channel' => 'mail']],
                                        ['name' => 'kernel.reset'],
                                    ],
                                    'decorates' => 'mailer',
                                    'arguments' => ['CANARY_SECRET_VALUE'],
                                ],
                            ],
                            'aliases' => [
                                'mailer' => ['service' => 'app.mailer', 'public' => true],
                            ],
                        ];
                    } elseif (isset($input->arguments['--types'])) {
                        $result = ['types' => ['App\\MailerInterface' => ['app.mailer']]];
                    } else {
                        $result = ['parameters' => [
                            'app.api_key' => 'CANARY_SECRET_VALUE',
                            'app.storage_dir' => '/private/storage',
                        ]];
                    }
                    $output->write(json_encode($result, JSON_THROW_ON_ERROR));

                    return 0;
                }
            }
            PHP);
    }

    private function writeAutoloader(string $version): void
    {
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', \sprintf(<<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string
                {
                    return %s;
                }
            }
            PHP,
            var_export($version, true),
        ));
    }
}
