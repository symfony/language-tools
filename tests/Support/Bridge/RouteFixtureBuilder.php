<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class RouteFixtureBuilder
{
    public function __construct(
        private readonly BridgeFixtureWorkspace $workspace,
        private readonly FakeFrameworkPrelude $prelude = new FakeFrameworkPrelude(),
    ) {
    }

    public function writeRouteApplication(string $kernelNamespace = 'App'): void
    {
        $source = $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            __CONSOLE_IO__
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
            interface RequestContextAwareInterface
            {
                public function getContext(): RequestContext;
            }
            final class RequestContext
            {
                public function getParameters(): array { return ['tenant' => 'acme']; }
            }
            final class RouteCollection
            {
                public function __construct(private array $resources) {}
                public function getResources(): array { return $this->resources; }
            }
            namespace App;
            final class Router implements \Symfony\Component\Routing\RouterInterface, \Symfony\Component\Routing\RequestContextAwareInterface
            {
                public function getContext(): \Symfony\Component\Routing\RequestContext
                {
                    return new \Symfony\Component\Routing\RequestContext();
                }
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
                public function hasParameter(string $name): bool { return 'kernel.default_locale' === $name; }
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
            __FRAMEWORK_APPLICATION__
            namespace App\Endpoint;
            if (is_file(\dirname(__DIR__).'/config/endpoints/LegacyEndpoints.php')) {
                require \dirname(__DIR__).'/config/endpoints/LegacyEndpoints.php';
            }
            PHP,
            applicationMembers: <<<'PHP'
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
PHP,
        );
        $this->workspace->write('vendor/autoload.php', str_replace('namespace App;', 'namespace '.$kernelNamespace.';', $source));
    }

    public function writeMultiRootKernelApplication(): void
    {
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            namespace Symfony\Component\HttpKernel;
            interface KernelInterface
            {
            }
            __CONSOLE_IO__
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
            __FRAMEWORK_APPLICATION__
            PHP,
            applicationMembers: <<<'PHP'
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
PHP,
        ));
    }

    public function writeSharedKernelApplication(): void
    {
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            namespace Symfony\Component\DependencyInjection;
            interface EnvVarProcessorInterface
            {
                public static function getProvidedTypes(): array;
            }
            final class EnvVarProcessor implements EnvVarProcessorInterface
            {
                public static function getProvidedTypes(): array { return ['string' => 'string']; }
            }
            __CONSOLE_IO__
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
            __FRAMEWORK_APPLICATION__
            PHP,
            applicationMembers: <<<'PHP'
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
PHP,
            applicationConstructor: <<<'PHP'
private static int $instances = 0;
public function __construct(object $kernel)
{
    if (1 !== ++self::$instances) { throw new \RuntimeException('Application constructed more than once.'); }
}
PHP,
        ));
    }
}
