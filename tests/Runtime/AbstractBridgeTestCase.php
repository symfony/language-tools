<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;

abstract class AbstractBridgeTestCase extends TestCase
{
    protected string $temporaryDirectory;

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

    protected function removeVarDirectory(): void
    {
        @unlink($this->temporaryDirectory.'/var/cache/translations/catalogue.en.stale.php');
        @rmdir($this->temporaryDirectory.'/var/cache/translations');
        @rmdir($this->temporaryDirectory.'/var/cache');
        @rmdir($this->temporaryDirectory.'/var');
    }

    protected function writeTwigComponentApplication(bool $withUnnameableComponent = false): void
    {
        $unnameable = $withUnnameableComponent
            ? '"Vendor\\\\Hidden\\\\Component": {"class": "Vendor\\\\Hidden\\\\Component", "tags": [{"name": "twig.component", "parameters": {"expose_public_props": true}}]},'
            : '';
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', str_replace(
            '__UNNAMEABLE__',
            $unnameable,
            <<<'PHP'
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
            namespace Symfony\UX\TwigComponent;
            final class ComponentFactory
            {
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
                    $output->write("\n ! [NOTE] Some deprecation notice written to the console output.\n\n");
                    if ('debug:config' === ($input->arguments['command'] ?? null)) {
                        $output->write(json_encode(['twig_component' => [
                            'defaults' => [
                                'App\\Twig\\Components\\' => ['template_directory' => 'components', 'name_prefix' => ''],
                                'Acme\\Ui\\' => ['template_directory' => 'ui', 'name_prefix' => 'acme'],
                            ],
                            'anonymous_template_directory' => 'components',
                        ]], JSON_THROW_ON_ERROR));

                        return 0;
                    }

                    if ('ux.twig_component.twig_renderer' === ($input->arguments['--tag'] ?? null)) {
                        $output->write(<<<'JSON'
                            {
                                "definitions": {
                                    ".ux_icons.twig_icon_runtime": {
                                        "class": "Symfony\\UX\\Icons\\Twig\\UXIconRuntime",
                                        "tags": [{"name": "ux.twig_component.twig_renderer", "parameters": {"key": "ux:icon"}}]
                                    },
                                    "invalid.twig_renderer": {
                                        "class": "Vendor\\InvalidTwigRenderer",
                                        "tags": [{"name": "ux.twig_component.twig_renderer", "parameters": {"key": "Invalid:Renderer"}}]
                                    }
                                },
                                "aliases": [],
                                "services": []
                            }
                            JSON);

                        return 0;
                    }

                    $output->write(<<<'JSON'
                        {
                            "definitions": {
                                __UNNAMEABLE__
                                "App\\Twig\\Components\\Alert": {
                                    "class": "App\\Twig\\Components\\Alert",
                                    "tags": [{"name": "twig.component", "parameters": {"expose_public_props": true}}]
                                },
                                "App\\Twig\\Components\\Form\\Input": {
                                    "class": "App\\Twig\\Components\\Form\\Input",
                                    "tags": [{"name": "twig.component", "parameters": {"expose_public_props": true}}]
                                },
                                "Acme\\Ui\\Badge": {
                                    "class": "Acme\\Ui\\Badge",
                                    "tags": [{"name": "twig.component", "parameters": {"expose_public_props": true}}]
                                },
                                ".ux_icons.twig_component.icon": {
                                    "class": "Symfony\\UX\\Icons\\Twig\\UXIconComponent",
                                    "tags": [
                                        {"name": "twig.component", "parameters": {"key": "UX:Icon"}},
                                        {"name": "kernel.reset", "parameters": {"method": "reset"}}
                                    ]
                                }
                            },
                            "aliases": [],
                            "services": []
                        }
                        JSON);

                    return 0;
                }
            }
            PHP,
        ));
    }

    protected function writeRouteApplication(string $kernelNamespace = 'App'): void
    {
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', str_replace('namespace App;', 'namespace '.$kernelNamespace.';', <<<'PHP'
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
            namespace Symfony\Component\Translation;
            interface TranslatorBagInterface
            {
            }
            namespace App;
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function getCacheDir(): string { return dirname(__DIR__).'/var/cache'; }
                public function getBuildDir(): string { return $this->getCacheDir(); }
                public function shutdown(): void {}
            }
            namespace Symfony\Bundle\FrameworkBundle\Console;
            final class Application
            {
                public function __construct(object $kernel) {}
                public function setAutoExit(bool $autoExit): void {}
                public function run(object $input, object $output): int
                {
                    $output->write("\n ! [NOTE] Some deprecation notice written to the console output.\n\n");
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
                        'localized_home.en' => [
                            'path' => '/en',
                            'method' => 'GET',
                            'scheme' => 'ANY',
                            'host' => 'ANY',
                            'defaults' => [
                                '_locale' => 'en',
                                '_canonical_route' => 'localized_home',
                                '_controller' => 'App\\Controller\\HomeController',
                            ],
                        ],
                        'localized_home.fr' => [
                            'path' => '/fr',
                            'method' => 'GET',
                            'scheme' => 'ANY',
                            'host' => 'ANY',
                            'defaults' => [
                                '_locale' => 'fr',
                                '_canonical_route' => 'localized_home',
                                '_controller' => 'App\\Controller\\HomeController',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR));
                    $output->write("\nTrailing console noise after the payload.\n");

                    return 0;
                }
            }
            PHP));
    }

    protected function writeMultiRootKernelApplication(): void
    {
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '8.0.6'; }
            }
            namespace Symfony\Component\HttpKernel;
            interface KernelInterface
            {
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
            namespace Tests;
            final class Kernel
            {
                public function __construct(string $environment, bool $debug)
                {
                    throw new \RuntimeException('The test kernel must never boot.');
                }
            }
            namespace Acme;
            final class Kernel implements \Symfony\Component\HttpKernel\KernelInterface
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
                            'method' => 'ANY',
                            'scheme' => 'ANY',
                            'host' => 'ANY',
                            'defaults' => [],
                        ],
                    ], JSON_THROW_ON_ERROR));

                    return 0;
                }
            }
            PHP);
    }

    protected function writeContainerApplication(): void
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

    protected function writeSharedKernelApplication(): void
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
                private static int $instances = 0;
                public function __construct(object $kernel)
                {
                    if (1 !== ++self::$instances) { throw new \RuntimeException('Application constructed more than once.'); }
                }
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

    protected function writeConfigurationApplication(): void
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
            final class BrokenExtension
            {
                public function getAlias(): string { return 'broken'; }
                public function getConfiguration(array $config, object $container): object { throw new \RuntimeException('CANARY_CONFIGURATION_EXCEPTION'); }
            }
            final class BrokenBundle
            {
                public function getContainerExtension(): object { return new BrokenExtension(); }
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

    protected function writeEventApplication(): void
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
            namespace Symfony\Component\EventDispatcher;
            interface EventSubscriberInterface
            {
                public static function getSubscribedEvents(): array;
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
            namespace App\Event;
            final class OrderPlaced {}
            final class OrderShipped {}
            namespace App\EventListener;
            final class NotifyCustomer
            {
                public function __construct() { throw new \RuntimeException('Listeners must not be instantiated.'); }
                public function onOrderPlaced(\App\Event\OrderPlaced $event): void {}
            }
            final class AuditOrder
            {
                public function __construct() { throw new \RuntimeException('Listeners must not be instantiated.'); }
                public function __invoke(object $event): void {}
            }
            namespace App\EventSubscriber;
            final class ShipmentSubscriber implements \Symfony\Component\EventDispatcher\EventSubscriberInterface
            {
                public function __construct() { throw new \RuntimeException('Subscribers must not be instantiated.'); }
                public static function getSubscribedEvents(): array
                {
                    return ['App\\Event\\OrderShipped' => [['recordShipment', 5]]];
                }
            }
            namespace App;
            final class Container
            {
                public function hasParameter(string $name): bool { return 'event_dispatcher.event_aliases' === $name; }
                public function getParameter(string $name): array { return ['App\\Event\\OrderShipped' => 'order.shipped']; }
            }
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function shutdown(): void {}
                public function getContainer(): Container { return new Container(); }
            }
            namespace Symfony\Bundle\FrameworkBundle\Console;
            final class Application
            {
                public function __construct(object $kernel) {}
                public function setAutoExit(bool $autoExit): void {}
                public function run(object $input, object $output): int
                {
                    $definitions = 'kernel.event_listener' === ($input->arguments['--tag'] ?? null) ? [
                        'App\\EventListener\\NotifyCustomer' => [
                            'class' => 'App\\EventListener\\NotifyCustomer',
                            'tags' => [['name' => 'kernel.event_listener', 'parameters' => ['event' => null, 'method' => 'onOrderPlaced', 'priority' => 10, 'dispatcher' => null]]],
                        ],
                        'App\\EventListener\\AuditOrder' => [
                            'class' => 'App\\EventListener\\AuditOrder',
                            'tags' => [['name' => 'kernel.event_listener', 'parameters' => ['event' => 'legacy.order_placed']]],
                        ],
                    ] : [
                        'App\\EventSubscriber\\ShipmentSubscriber' => [
                            'class' => 'App\\EventSubscriber\\ShipmentSubscriber',
                            'tags' => [['name' => 'kernel.event_subscriber']],
                        ],
                    ];
                    $output->write(json_encode(['definitions' => $definitions], JSON_THROW_ON_ERROR));

                    return 0;
                }
            }
            PHP);
    }

    protected function writeSecurityApplication(): void
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

    protected function writeTwigApplicationWithoutDebugCommand(): void
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

    protected function writeUnregisteredSecurityApplication(): void
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

    protected function writeEnvironmentApplication(): void
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

    protected function writeAutoloader(string $version): void
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
