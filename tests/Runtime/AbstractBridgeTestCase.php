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
        @unlink($this->temporaryDirectory.'/vendor/autoload_runtime.php');
        @unlink($this->temporaryDirectory.'/vendor/autoload.php');
        @unlink($this->temporaryDirectory.'/vendor/symfony/validator/Constraints/Alpha.php');
        @unlink($this->temporaryDirectory.'/vendor/symfony/validator/Constraints/ExpressionLanguageProvider.php');
        @unlink($this->temporaryDirectory.'/vendor/symfony/validator/Constraints/Zulu.php');
        @rmdir($this->temporaryDirectory.'/vendor/symfony/validator/Constraints');
        @rmdir($this->temporaryDirectory.'/vendor/symfony/validator');
        @rmdir($this->temporaryDirectory.'/vendor/symfony');
        @unlink($this->temporaryDirectory.'/composer.json');
        @unlink($this->temporaryDirectory.'/config/broken.php');
        @unlink($this->temporaryDirectory.'/config/framework.yaml');
        @unlink($this->temporaryDirectory.'/config/http_endpoints.yaml');
        @rmdir($this->temporaryDirectory.'/config');
        @unlink($this->temporaryDirectory.'/var/cache/container.php');
        @rmdir($this->temporaryDirectory.'/var/cache');
        @rmdir($this->temporaryDirectory.'/var');
        @rmdir($this->temporaryDirectory.'/vendor');
        @rmdir($this->temporaryDirectory.'/templates');
        @rmdir($this->temporaryDirectory.'/src/ShopBundle/templates');
        @unlink($this->temporaryDirectory.'/src/ShopBundle/Resources/assets/controllers.json');
        @rmdir($this->temporaryDirectory.'/src/ShopBundle/Resources/assets');
        @rmdir($this->temporaryDirectory.'/src/ShopBundle/Resources');
        @rmdir($this->temporaryDirectory.'/src/ShopBundle');
        @unlink($this->temporaryDirectory.'/src/Entity/Book.php');
        @rmdir($this->temporaryDirectory.'/src/Entity');
        @rmdir($this->temporaryDirectory.'/src');
        @unlink($this->temporaryDirectory.'/vendor/acme/ux-widget/assets/dist/widget_controller.js');
        @rmdir($this->temporaryDirectory.'/vendor/acme/ux-widget/assets/dist');
        @unlink($this->temporaryDirectory.'/vendor/acme/ux-widget/assets/package.json');
        @rmdir($this->temporaryDirectory.'/vendor/acme/ux-widget/assets');
        @rmdir($this->temporaryDirectory.'/vendor/acme/ux-widget');
        @rmdir($this->temporaryDirectory.'/vendor/acme');
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
            namespace Symfony\Component\Filesystem;
            final class Path
            {
                public static function canonicalize(string $path): string { return rtrim(str_replace('\\', '/', $path), '/'); }
                public static function isBasePath(string $base, string $path): bool { return $base === $path || str_starts_with($path, $base.'/'); }
                public static function makeRelative(string $path, string $base): string { return ltrim(substr($path, strlen($base)), '/'); }
            }
            namespace Symfony\Component\Config\Resource;
            final class FileResource
            {
                public function __construct(private string $resource) {}
                public function getResource(): string { return $this->resource; }
            }
            final class DirectoryResource
            {
            }
            final class ReflectionClassResource
            {
                public function __construct(private string $className) {}
                public function __toString(): string { return 'reflection.'.$this->className; }
            }
            namespace Symfony\Component\Routing;
            interface RouterInterface
            {
                public function getRouteCollection(): RouteCollection;
            }
            final class RouteCollection
            {
                public function __construct(private array $resources) {}
                public function getResources(): array { return $this->resources; }
            }
            namespace App;
            final class Router implements \Symfony\Component\Routing\RouterInterface
            {
                public function getRouteCollection(): \Symfony\Component\Routing\RouteCollection
                {
                    $root = dirname(__DIR__);

                    return new \Symfony\Component\Routing\RouteCollection([
                        new \Symfony\Component\Config\Resource\FileResource($root.'/config/http_endpoints.yaml'),
                        new \Symfony\Component\Config\Resource\FileResource($root.'/config/http_endpoints.yaml'),
                        new \Symfony\Component\Config\Resource\FileResource($root.'/vendor/autoload.php'),
                        new \Symfony\Component\Config\Resource\FileResource($root.'/var/cache/container.php'),
                        new \Symfony\Component\Config\Resource\DirectoryResource(),
                        new \Symfony\Component\Config\Resource\ReflectionClassResource(\App\Endpoint\LegacyEndpoints::class),
                        new \Symfony\Component\Config\Resource\ReflectionClassResource('App\\Endpoint\\MissingEndpoints'),
                    ]);
                }
            }
            final class Container
            {
                public function has(string $id): bool { return 'router' === $id; }
                public function get(string $id): object { return new Router(); }
            }
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function getCacheDir(): string { return dirname(__DIR__).'/var/cache'; }
                public function getBuildDir(): string { return $this->getCacheDir(); }
                public function getContainer(): Container { return new Container(); }
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
            namespace App\Endpoint;
            if (is_file(\dirname(__DIR__).'/config/endpoints/LegacyEndpoints.php')) {
                require \dirname(__DIR__).'/config/endpoints/LegacyEndpoints.php';
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
                public function __construct(string $name, private bool $normalizeKeys = true) { parent::__construct($name); }
                public function getChildren(): array
                {
                    return 'framework' === $this->getName()
                        ? [new ScalarNode('secret'), new self('csp', false)]
                        : [new ScalarNode('default-src')];
                }
                public function getXmlRemappings(): array { return 'framework' === $this->getName() ? [['alias', 'secret']] : []; }
                public function getKeyAttribute(): ?string { return 'framework' === $this->getName() ? 'name' : null; }
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
                    // mimic console log noise whose context decodes as JSON, as Contao emits in dev
                    $output->write('12:00:00 DEBUG [event] Notified '.json_encode(['driverOptions' => str_repeat('x', 2000)], JSON_THROW_ON_ERROR)."\n");
                    $output->write(json_encode(['definitions' => $definitions], JSON_THROW_ON_ERROR));

                    return 0;
                }
            }
            PHP);
    }

    protected function writeRuntimeFrontControllerApplication(): void
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
            namespace Symfony\Component\HttpKernel;
            interface KernelInterface {}
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
            namespace App\EventListener;
            final class NotifyCustomer
            {
                public function __construct() { throw new \RuntimeException('Listeners must not be instantiated.'); }
                public function onOrderPlaced(\App\Event\OrderPlaced $event): void {}
            }
            namespace Distribution;
            final class ConfiguredRuntime
            {
                public function __construct(private array $options = [])
                {
                    if ('composer' !== ($options['configured_option'] ?? null)
                        || 'environment' !== ($options['environment_option'] ?? null)
                        || realpath(dirname(__DIR__)) !== realpath($options['project_dir'] ?? '')
                    ) {
                        throw new \RuntimeException('The configured runtime options were not loaded.');
                    }
                }
                public function getResolver(\Closure $app): object
                {
                    return new class($app, $this->options) {
                        public function __construct(private \Closure $app, private array $options) {}
                        public function resolve(): array
                        {
                            return [$this->app, [['APP_ENV' => $this->options['env'] ?? 'prod', 'APP_DEBUG' => $this->options['debug'] ?? false]]];
                        }
                    };
                }
            }
            final class Kernel implements \Symfony\Component\HttpKernel\KernelInterface
            {
                public function __construct(public string $environment, public bool $debug) {}
                public function shutdown(): void {}
            }
            final class ConsoleApplication
            {
                public function __construct(private \Distribution\Kernel $kernel) {}
                public function getKernel(): \Distribution\Kernel { return $this->kernel; }
            }
            namespace Symfony\Bundle\FrameworkBundle\Console;
            final class Application
            {
                public function __construct(private object $kernel) {}
                public function setAutoExit(bool $autoExit): void {}
                public function run(object $input, object $output): int
                {
                    if (!$this->kernel instanceof \Distribution\Kernel || 'dev' !== $this->kernel->environment) {
                        return 1;
                    }
                    $definitions = 'kernel.event_listener' === ($input->arguments['--tag'] ?? null) ? [
                        'App\\EventListener\\NotifyCustomer' => [
                            'class' => 'App\\EventListener\\NotifyCustomer',
                            'tags' => [['name' => 'kernel.event_listener', 'parameters' => ['event' => null, 'method' => 'onOrderPlaced', 'priority' => 10]]],
                        ],
                    ] : [];
                    $output->write(json_encode(['definitions' => $definitions], JSON_THROW_ON_ERROR));

                    return 0;
                }
            }
            PHP);
        file_put_contents($this->temporaryDirectory.'/composer.json', json_encode([
            'extra' => ['runtime' => [
                'class' => 'Distribution\\ConfiguredRuntime',
                'configured_option' => 'composer',
                'project_dir' => $this->temporaryDirectory,
            ]],
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($this->temporaryDirectory.'/vendor/autoload_runtime.php', <<<'PHP'
            <?php
            if (true === (require_once __DIR__.'/autoload.php')) {
                return;
            }
            throw new RuntimeException('The runtime must not run when the autoloader is already loaded.');
            PHP);
        mkdir($this->temporaryDirectory.'/bin');
        file_put_contents($this->temporaryDirectory.'/bin/console', <<<'PHP'
            <?php
            require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

            return static function (array $context): object {
                return new \Distribution\ConsoleApplication(new \Distribution\Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']));
            };
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

    protected function writeThemedTwigApplication(): void
    {
        mkdir($this->temporaryDirectory.'/templates', 0777, true);
        mkdir($this->temporaryDirectory.'/src/ShopBundle/templates', 0777, true);
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '8.0.6'; }
            }
            namespace Twig;
            final class Environment {}
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
            final class ShopBundle
            {
                public function __construct(private string $path) {}
                public function getName(): string { return 'ShopBundle'; }
                public function getPath(): string { return $this->path; }
            }
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function shutdown(): void {}
                public function getBundles(): array
                {
                    return [new ShopBundle(\dirname(__DIR__).'/src/ShopBundle')];
                }
            }
            namespace Symfony\Bundle\FrameworkBundle\Console;
            final class Application
            {
                public function __construct(object $kernel) {}
                public function setAutoExit(bool $autoExit): void {}
                public function has(string $name): bool { return true; }
                public function run(object $input, object $output): int
                {
                    // a theme loader hides every filesystem path from debug:twig
                    $result = 'debug:twig' === $input->arguments['command']
                        ? ['globals' => ['app' => []], 'loader_paths' => []]
                        : ['twig' => ['default_path' => \dirname(__DIR__).'/templates', 'paths' => []]];
                    $output->write(json_encode($result, JSON_THROW_ON_ERROR));

                    return 0;
                }
            }
            PHP);
    }

    protected function writeThemedStimulusApplication(): void
    {
        mkdir($this->temporaryDirectory.'/src/ShopBundle/Resources/assets', 0777, true);
        file_put_contents($this->temporaryDirectory.'/src/ShopBundle/Resources/assets/controllers.json', json_encode([
            'controllers' => ['@acme/ux-widget' => ['widget' => ['enabled' => true, 'fetch' => 'eager']]],
        ], \JSON_THROW_ON_ERROR));
        mkdir($this->temporaryDirectory.'/vendor/acme/ux-widget/assets/dist', 0777, true);
        file_put_contents($this->temporaryDirectory.'/vendor/acme/ux-widget/assets/package.json', json_encode([
            'symfony' => ['controllers' => ['widget' => ['main' => 'dist/widget_controller.js', 'name' => 'acme/widget']]],
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($this->temporaryDirectory.'/vendor/acme/ux-widget/assets/dist/widget_controller.js', "export default class extends Controller {\n    refresh() {}\n}\n");
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '8.0.6'; }
                public static function isInstalled(string $package): bool { return 'acme/ux-widget' === $package; }
                public static function getInstallPath(string $package): string { return \dirname(__DIR__).'/vendor/acme/ux-widget'; }
            }
            namespace Symfony\UX\StimulusBundle;
            final class StimulusBundle
            {
                public function getPath(): string { return __DIR__; }
            }
            namespace Symfony\Component\Filesystem;
            final class Path
            {
                public static function join(string ...$parts): string { return implode('/', $parts); }
                public static function canonicalize(string $path): string { return str_replace('\\', '/', $path); }
                public static function isBasePath(string $base, string $path): bool { return str_starts_with($path, rtrim($base, '/').'/'); }
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
            final class ShopBundle
            {
                public function __construct(private string $path) {}
                public function getName(): string { return 'ShopBundle'; }
                public function getPath(): string { return $this->path; }
            }
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function shutdown(): void {}
                public function getBundles(): array
                {
                    return [new \Symfony\UX\StimulusBundle\StimulusBundle(), new ShopBundle(\dirname(__DIR__).'/src/ShopBundle')];
                }
            }
            namespace Symfony\Bundle\FrameworkBundle\Console;
            final class Application
            {
                public function __construct(object $kernel) {}
                public function setAutoExit(bool $autoExit): void {}
                public function has(string $name): bool { return true; }
                public function run(object $input, object $output): int
                {
                    $project = \dirname(__DIR__);
                    $output->write(json_encode(['stimulus' => [
                        'controller_paths' => [$project.'/assets/controllers'],
                        'controllers_json' => $project.'/assets/controllers.json',
                    ]], JSON_THROW_ON_ERROR));

                    return 0;
                }
            }
            PHP);
    }

    protected function writeDoctrineApplication(): void
    {
        mkdir($this->temporaryDirectory.'/src/Entity', 0777, true);
        file_put_contents($this->temporaryDirectory.'/src/Entity/Book.php', <<<'PHP'
            <?php
            namespace App\Entity;
            class Book
            {
            }
            PHP);
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '8.0.6'; }
            }
            namespace Doctrine\Persistence;
            interface ManagerRegistry
            {
            }
            namespace Doctrine\ORM\Mapping;
            class ClassMetadata
            {
                public $customRepositoryClassName = 'App\Repository\BookRepository';
                public function getName(): string { return 'App\Entity\Book'; }
                public function getReflectionClass(): \ReflectionClass
                {
                    require_once \dirname(__DIR__).'/src/Entity/Book.php';
                    return new \ReflectionClass('App\Entity\Book');
                }
                public function getFieldNames(): array { return ['title']; }
                public function getTypeOfField(string $name): string { return 'string'; }
                public function getAssociationNames(): array { return ['author']; }
                public function getAssociationTargetClass(string $name): string { return 'App\Entity\Author'; }
            }
            namespace App;
            final class MetadataFactory
            {
                public function getAllMetadata(): array { return [new \Doctrine\ORM\Mapping\ClassMetadata()]; }
            }
            final class Manager
            {
                public function getMetadataFactory(): MetadataFactory { return new MetadataFactory(); }
            }
            final class Registry implements \Doctrine\Persistence\ManagerRegistry
            {
                public function getManagers(): array { return ['default' => new Manager()]; }
            }
            final class Container
            {
                public function has(string $id): bool { return 'doctrine' === $id; }
                public function get(string $id): object { return new Registry(); }
            }
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function boot(): void {}
                public function shutdown(): void {}
                public function getContainer(): Container { return new Container(); }
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
