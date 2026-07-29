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
                'name' => 'article_show',
                'path' => '/article/{id}',
                'methods' => ['GET'],
                'schemes' => [],
                'host' => null,
                'controller' => 'App\\Controller\\ArticleController::show',
            ],
            [
                'name' => 'homepage',
                'path' => '/',
                'methods' => [],
                'schemes' => ['https'],
                'host' => 'example.com',
                'controller' => null,
            ],
        ], $result['sections']['routes']['items']);
        self::assertTrue($result['sections']['routes']['complete']);
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
                        'homepage' => [
                            'path' => '/',
                            'methods' => [],
                            'schemes' => ['https'],
                            'host' => 'example.com',
                            'defaults' => [],
                        ],
                        'article_show' => [
                            'path' => '/article/{id}',
                            'methods' => ['GET'],
                            'schemes' => [],
                            'host' => '',
                            'defaults' => ['_controller' => 'App\\Controller\\ArticleController::show'],
                        ],
                    ], JSON_THROW_ON_ERROR));

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
