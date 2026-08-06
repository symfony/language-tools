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
        self::assertStringNotContainsString('CANARY_SECRET_', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame([], $result['errors']);
        self::assertIsArray($result['sections']);
        self::assertIsArray($result['sections']['container']);
        self::assertFalse($result['sections']['container']['servicesComplete'] ?? null);
        self::assertTrue($result['sections']['container']['parametersComplete'] ?? null);
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
                'decorationStack' => ['app.mailer', 'mailer.inner'],
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
                'decorationStack' => [],
                'autowiringTypes' => [],
            ],
        ], $result['sections']['container']['items']);
        self::assertSame([
            ['name' => 'app.api_key', 'deprecation' => null],
            ['name' => 'app.storage_dir', 'deprecation' => 'Since symfony/dependency-injection 8.0: Use app.data_dir.'],
            ['name' => 'app.structured', 'deprecation' => null],
        ], $result['sections']['container']['parameters']);
    }

    public function testUsesOneKernelForAllRequestedSections(): void
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

    public function testExportsPublicBundleConfigurationTrees(): void
    {
        $this->writeConfigurationApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=configuration 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        self::assertStringNotContainsString('CANARY_SECRET_', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['configuration'] ?? null);
        self::assertIsArray($result['sections']['configuration']['bundles'] ?? null);
        $bundle = $result['sections']['configuration']['bundles'][0] ?? null;
        self::assertIsArray($bundle);
        self::assertIsArray($bundle['tree'] ?? null);
        self::assertIsArray($bundle['tree']['children'] ?? null);
        $child = $bundle['tree']['children'][0] ?? null;
        self::assertIsArray($child);
        self::assertSame('framework', $bundle['alias'] ?? null);
        self::assertSame('scalar', $child['type'] ?? null);
        self::assertSame('string', $child['defaultSummary'] ?? null);
    }

    public function testExportsEnvironmentProcessorMetadataWithoutValues(): void
    {
        $this->writeEnvironmentApplication();
        $previousSecret = getenv('APP_SECRET');
        putenv('APP_SECRET=CANARY_SECRET_ENVIRONMENT_VALUE');

        exec(\sprintf(
            '%s %s --project=%s --sections=environment 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);
        if (false === $previousSecret) {
            putenv('APP_SECRET');
        } else {
            putenv('APP_SECRET='.$previousSecret);
        }

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        self::assertStringNotContainsString('CANARY_SECRET_', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['environment'] ?? null);
        self::assertSame([
            ['name' => 'int', 'type' => 'int'],
            ['name' => 'json', 'type' => 'array'],
        ], $result['sections']['environment']['processors'] ?? null);
    }

    public function testNormalizesEventDispatcherMetadata(): void
    {
        $this->writeEventApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=events 2>&1',
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
        self::assertIsArray($result['sections']['events'] ?? null);
        self::assertSame([
            ['name' => 'App\\Event\\OrderPlaced', 'class' => 'App\\Event\\OrderPlaced'],
            ['name' => 'legacy.order_placed', 'class' => null],
        ], $result['sections']['events']['events'] ?? null);
        self::assertSame([
            ['event' => 'App\\Event\\OrderPlaced', 'class' => 'App\\EventListener\\NotifyCustomer', 'method' => 'onOrderPlaced', 'priority' => 10],
            ['event' => 'legacy.order_placed', 'class' => 'App\\EventListener\\AuditOrder', 'method' => '__invoke', 'priority' => 0],
        ], $result['sections']['events']['listeners'] ?? null);
    }

    public function testNormalizesSecurityMetadataWithoutExportingProviderValues(): void
    {
        $this->writeSecurityApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=security 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        self::assertStringNotContainsString('CANARY_SECRET_', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['security'] ?? null);
        self::assertSame([
            ['name' => 'main', 'provider' => 'users', 'enabled' => true, 'stateless' => true, 'lazy' => false, 'authenticators' => ['App\\Security\\Authenticator']],
        ], $result['sections']['security']['firewalls'] ?? null);
        self::assertSame([['name' => 'users', 'type' => 'memory']], $result['sections']['security']['providers'] ?? null);
        self::assertSame([
            ['name' => 'ROLE_ADMIN', 'inheritedRoles' => ['ROLE_USER']],
            ['name' => 'ROLE_USER', 'inheritedRoles' => []],
        ], $result['sections']['security']['roles'] ?? null);
        self::assertSame([['class' => 'App\\Security\\PostVoter']], $result['sections']['security']['voters'] ?? null);
    }

    public function testReportsUnavailableOptionalAssetMapper(): void
    {
        $this->writeAutoloader('8.0.6');

        exec(\sprintf(
            '%s %s --project=%s --sections=assets 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $result = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $exitCode);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['assets'] ?? null);
        self::assertFalse($result['sections']['assets']['assetsComplete'] ?? null);
        self::assertFalse($result['sections']['assets']['importMapComplete'] ?? null);
        self::assertSame([], $result['sections']['assets']['assets'] ?? null);
        self::assertSame([], $result['sections']['assets']['importMap'] ?? null);
    }

    public function testReportsUnavailableOptionalStimulusBundle(): void
    {
        $this->writeAutoloader('8.0.6');

        exec(\sprintf(
            '%s %s --project=%s --sections=stimulus 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $result = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $exitCode);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['stimulus'] ?? null);
        self::assertFalse($result['sections']['stimulus']['complete'] ?? null);
        self::assertSame([], $result['sections']['stimulus']['controllers'] ?? null);
    }

    public function testReportsUnavailableOptionalMetadataComponents(): void
    {
        $this->writeAutoloader('8.0.6');

        exec(\sprintf(
            '%s %s --project=%s --sections=metadata 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $result = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $exitCode);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['metadata'] ?? null);
        self::assertFalse($result['sections']['metadata']['formsComplete'] ?? null);
        self::assertFalse($result['sections']['metadata']['constraintsComplete'] ?? null);
        self::assertSame([], $result['sections']['metadata']['forms'] ?? null);
        self::assertSame([], $result['sections']['metadata']['constraints'] ?? null);
    }

    public function testReportsUnavailableTwigDebugCommandAsAWarning(): void
    {
        $this->writeTwigApplicationWithoutDebugCommand();

        exec(\sprintf(
            '%s %s --project=%s --sections=twig 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $result = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $exitCode);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertSame([
            'complete' => false,
            'generation' => hash('sha256', '[[],[]]'),
            'paths' => [],
            'globals' => [],
            'resources' => [],
            'warnings' => ['The debug:twig command is unavailable.'],
        ], $result['sections']['twig'] ?? null);
    }

    public function testIgnoresAnUnregisteredSecurityBundle(): void
    {
        $this->writeUnregisteredSecurityApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=security 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $result = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $exitCode);
        self::assertIsArray($result);
        self::assertSame([], $result['errors'] ?? null);
        self::assertIsArray($result['sections'] ?? null);
        self::assertIsArray($result['sections']['security'] ?? null);
        self::assertSame([], $result['sections']['security']['firewalls'] ?? null);
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
                                    'deprecated' => true,
                                    'deprecation_message' => 'Use app.new_mailer instead.',
                                    'tags' => [
                                        'monolog.logger' => [['channel' => 'mail']],
                                        ['name' => 'kernel.reset'],
                                    ],
                                    'decorates' => 'mailer',
                                    'decoration_stack' => [
                                        ['id' => 'app.mailer', 'class' => 'App\\Mailer', 'priority' => 1],
                                        ['id' => 'mailer.inner', 'class' => 'App\\BaseMailer', 'priority' => 0],
                                    ],
                                    'arguments' => ['CANARY_SECRET_VALUE'],
                                ],
                            ],
                            'aliases' => [
                                'mailer' => ['service' => 'app.mailer', 'public' => true],
                            ],
                        ];
                    } elseif (isset($input->arguments['--types'])) {
                        $result = [
                            'definitions' => [],
                            'aliases' => [
                                'App\\MailerInterface' => ['service' => 'app.mailer', 'public' => false],
                            ],
                            'services' => [],
                        ];
                    } else {
                        $result = ['parameters' => [
                            'app.api_key' => 'CANARY_SECRET_VALUE',
                            'app.storage_dir' => '/private/storage',
                            'app.structured' => [
                                'name' => 'CANARY_SECRET_NAME',
                                'deprecation' => 'CANARY_SECRET_DEPRECATION',
                            ],
                            '_deprecations' => [
                                'app.api_key' => 'CANARY_SECRET_PARAMETER_DEPRECATION',
                                'app.storage_dir' => 'Since symfony/dependency-injection 8.0: Use app.data_dir.',
                            ],
                        ]];
                    }
                    $output->write(json_encode($result, JSON_THROW_ON_ERROR));

                    return 0;
                }
            }
            PHP);
    }

    private function writeSharedKernelApplication(): void
    {
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '8.0.6'; }
            }
            namespace Symfony\Component\DependencyInjection;
            interface EnvVarProcessorInterface
            {
                public static function getProvidedTypes(): array;
            }
            final class EnvVarProcessor implements EnvVarProcessorInterface
            {
                public static function getProvidedTypes(): array { return ['string' => 'string']; }
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
                private static int $instances = 0;
                private int $boots = 0;
                public function __construct(string $environment, bool $debug)
                {
                    if (1 !== ++self::$instances) { throw new \RuntimeException('Kernel constructed more than once.'); }
                }
                public function boot(): void
                {
                    if (1 !== ++$this->boots) { throw new \RuntimeException('Kernel booted more than once.'); }
                }
                public function shutdown(): void {}
            }
            namespace Symfony\Bundle\FrameworkBundle\Console;
            final class Application
            {
                public function __construct(object $kernel) {}
                public function setAutoExit(bool $autoExit): void {}
                public function run(object $input, object $output): int
                {
                    $command = $input->arguments['command'];
                    $result = 'debug:router' === $command ? [] : match (true) {
                        isset($input->arguments['--parameters']) => ['parameters' => []],
                        default => ['definitions' => [], 'aliases' => [], 'services' => []],
                    };
                    $output->write(json_encode($result, JSON_THROW_ON_ERROR));

                    return 0;
                }
            }
            PHP);
    }

    private function writeConfigurationApplication(): void
    {
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '8.0.6'; }
            }
            namespace Symfony\Component\Filesystem;
            final class Path
            {
                public static function join(string ...$paths): string { return implode('/', $paths); }
            }
            namespace Symfony\Component\DependencyInjection;
            final class ContainerBuilder
            {
                public function setParameter(string $name, mixed $value): void {}
                public function registerExtension(object $extension): void {}
            }
            namespace Symfony\Component\Config\Definition;
            abstract class TestNode
            {
                public function __construct(private string $name) {}
                public function getName(): string { return $this->name; }
                public function isRequired(): bool { return false; }
                public function hasDefaultValue(): bool { return false; }
                public function getDefaultValue(): mixed { return null; }
                public function getInfo(): ?string { return null; }
                public function getExample(): mixed { return null; }
                public function isDeprecated(): bool { return false; }
            }
            final class ScalarNode extends TestNode
            {
                public function hasDefaultValue(): bool { return true; }
                public function getDefaultValue(): mixed { return 'CANARY_SECRET_CONFIG_DEFAULT'; }
            }
            final class ArrayNode extends TestNode
            {
                public function getChildren(): array { return [new ScalarNode('secret')]; }
            }
            namespace App;
            final class TreeBuilder
            {
                public function buildTree(): object { return new \Symfony\Component\Config\Definition\ArrayNode('framework'); }
            }
            final class Configuration
            {
                public function getConfigTreeBuilder(): object { return new TreeBuilder(); }
            }
            final class Extension
            {
                public function getAlias(): string { return 'framework'; }
                public function getConfiguration(array $config, object $container): object { return new Configuration(); }
            }
            final class Bundle
            {
                public function getContainerExtension(): object { return new Extension(); }
            }
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function boot(): void {}
                public function shutdown(): void {}
                public function getBundles(): array { return [new Bundle()]; }
            }
            PHP);
    }

    private function writeEventApplication(): void
    {
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '8.0.6'; }
            }
            namespace Symfony\Contracts\EventDispatcher;
            interface EventDispatcherInterface {}
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
            namespace App\Event;
            final class OrderPlaced {}
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
                        'legacy.order_placed' => [[
                            'type' => 'function',
                            'name' => '__invoke',
                            'class' => 'App\\EventListener\\AuditOrder',
                            'priority' => 0,
                        ]],
                        'App\\Event\\OrderPlaced' => [[
                            'type' => 'function',
                            'name' => 'onOrderPlaced',
                            'class' => 'App\\EventListener\\NotifyCustomer',
                            'priority' => 10,
                        ]],
                    ], JSON_THROW_ON_ERROR));

                    return 0;
                }
            }
            PHP);
    }

    private function writeSecurityApplication(): void
    {
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '8.0.6'; }
            }
            namespace Symfony\Bundle\SecurityBundle;
            final class SecurityBundle {}
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
                public function getBundles(): array { return [new \Symfony\Bundle\SecurityBundle\SecurityBundle()]; }
            }
            namespace Symfony\Bundle\FrameworkBundle\Console;
            final class Application
            {
                public function __construct(object $kernel) {}
                public function setAutoExit(bool $autoExit): void {}
                public function run(object $input, object $output): int
                {
                    $result = 'debug:config' === $input->arguments['command'] ? [
                        'security' => [
                            'providers' => [
                                'users' => ['memory' => ['users' => ['admin' => ['password' => 'CANARY_SECRET_PASSWORD']]]],
                            ],
                            'firewalls' => [
                                'main' => [
                                    'provider' => 'users',
                                    'security' => true,
                                    'stateless' => true,
                                    'lazy' => false,
                                    'custom_authenticators' => ['App\\Security\\Authenticator'],
                                ],
                            ],
                            'role_hierarchy' => ['ROLE_ADMIN' => ['ROLE_USER']],
                            'access_control' => [['roles' => ['ROLE_ADMIN']]],
                        ],
                    ] : [
                        'definitions' => [
                            'app.voter' => ['class' => 'App\\Security\\PostVoter'],
                        ],
                    ];
                    $output->write("[deprecation] Outdated application configuration.\n[\n  exception => configuration\n]\n");
                    $output->write(json_encode($result, JSON_THROW_ON_ERROR));

                    return 0;
                }
            }
            PHP);
    }

    private function writeTwigApplicationWithoutDebugCommand(): void
    {
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '8.0.6'; }
            }
            namespace Twig;
            final class Environment {}
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
                public function has(string $name): bool { return false; }
            }
            PHP);
    }

    private function writeUnregisteredSecurityApplication(): void
    {
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '8.0.6'; }
            }
            namespace Symfony\Bundle\SecurityBundle;
            final class SecurityBundle {}
            namespace App;
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function getBundles(): array { return []; }
                public function shutdown(): void {}
            }
            PHP);
    }

    private function writeEnvironmentApplication(): void
    {
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '8.0.6'; }
            }
            namespace Symfony\Component\DependencyInjection;
            interface EnvVarProcessorInterface
            {
                public static function getProvidedTypes(): array;
            }
            final class EnvVarProcessor implements EnvVarProcessorInterface
            {
                public static function getProvidedTypes(): array { return ['json' => 'array', 'int' => 'int']; }
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
                    $output->write('{"definitions":[]}');

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
