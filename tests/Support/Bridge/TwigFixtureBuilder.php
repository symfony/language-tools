<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class TwigFixtureBuilder
{
    public function __construct(
        private readonly BridgeFixtureWorkspace $workspace,
        private readonly FakeFrameworkPrelude $prelude = new FakeFrameworkPrelude(),
    ) {
    }

    public function writeThemedTwigApplication(): void
    {
        $this->writeThemedTwigApplicationWithConfiguration(
            '',
            '',
            "['twig' => ['default_path' => \\dirname(__DIR__).'/templates', 'paths' => []]]",
        );
    }

    public function writeThemedTwigApplicationWithEffectiveConfiguration(): void
    {
        $this->workspace->makeDirectory('command-templates');
        $this->workspace->makeDirectory('effective-templates');
        $this->writeThemedTwigApplicationWithConfiguration(
            <<<'PHP'
                namespace Symfony\Component\Config\Definition;
                interface ConfigurationInterface {}
                final class Processor {}
                namespace Symfony\Component\DependencyInjection\Extension;
                interface ConfigurationExtensionInterface {}
                interface ExtensionInterface {}
                namespace Symfony\Component\DependencyInjection\Compiler;
                final class ValidateEnvPlaceholdersPass
                {
                    public function __construct(private array $configurations) {}
                    public function getExtensionConfig(): array
                    {
                        try { return $this->configurations; } finally { $this->configurations = []; }
                    }
                }
                namespace Symfony\Component\DependencyInjection;
                final class ContainerBuilder
                {
                    private object $pass;
                    public function __construct(array $configurations)
                    {
                        $this->pass = new \Symfony\Component\DependencyInjection\Compiler\ValidateEnvPlaceholdersPass($configurations);
                    }
                    public function getCompiler(): object
                    {
                        return new class {
                            public function compile(object $container): void {}
                        };
                    }
                    public function getCompilerPassConfig(): object
                    {
                        return new class($this->pass) {
                            public function __construct(private object $pass) {}
                            public function getPasses(): array { return [$this->pass]; }
                        };
                    }
                    public function getParameterBag(): object
                    {
                        return new class {
                            public function resolveValue(mixed $value): mixed { return $value; }
                        };
                    }
                    public function resolveEnvPlaceholders(mixed $value, mixed $format = null): mixed { return $value; }
                }
                PHP,
            <<<'PHP'
                    public function boot(): void {}
                    public function getContainer(): object
                    {
                        return new class {
                            public function has(string $id): bool { return false; }
                            public function get(string $id): never { throw new \LogicException(); }
                        };
                    }
                    public function buildContainer(): \Symfony\Component\DependencyInjection\ContainerBuilder
                    {
                        return new \Symfony\Component\DependencyInjection\ContainerBuilder([
                            'twig' => [
                                'default_path' => \dirname(__DIR__).'/effective-templates',
                                'paths' => [\dirname(__DIR__).'/effective-extra' => 'Effective'],
                            ],
                        ]);
                    }
                PHP,
            "['twig' => ['default_path' => \\dirname(__DIR__).'/command-templates', 'paths' => [\\dirname(__DIR__).'/command-extra' => 'Command']]]",
        );
    }

    private function writeThemedTwigApplicationWithConfiguration(string $configurationSupport, string $kernelConfiguration, string $commandConfiguration): void
    {
        $this->workspace->makeDirectory('templates');
        $this->workspace->makeDirectory('src/ShopBundle/templates');
        $source = str_replace(
            ['__CONFIGURATION_SUPPORT__', '__KERNEL_CONFIGURATION__', '__COMMAND_CONFIGURATION__'],
            [$configurationSupport, $kernelConfiguration, $commandConfiguration],
            $this->prelude->render(<<<'PHP'
                __INSTALLED_VERSIONS__
                __CONFIGURATION_SUPPORT__
                namespace Twig;
                final class Environment {}
                __CONSOLE_IO__
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
                __KERNEL_CONFIGURATION__
                }
                __FRAMEWORK_APPLICATION__
                PHP,
                applicationMembers: <<<'PHP'
    public function has(string $name): bool { return true; }
    public function run(object $input, object $output): int
    {
        // a theme loader hides every filesystem path from debug:twig
        $result = 'debug:twig' === $input->arguments['command']
            ? ['globals' => ['app' => []], 'loader_paths' => []]
            : __COMMAND_CONFIGURATION__;
        $output->write(json_encode($result, JSON_THROW_ON_ERROR));

        return 0;
    }
PHP,
            ),
        );
        $this->workspace->write('vendor/autoload.php', $source);
    }

    public function writeTwigApplicationWithDecoratedLoader(): void
    {
        $this->workspace->makeDirectory('templates');
        $this->workspace->makeDirectory('src/ShopBundle/templates');
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            namespace Twig\Loader;
            interface LoaderInterface {}
            final class FilesystemLoader implements LoaderInterface
            {
                public const MAIN_NAMESPACE = '__main__';
                public function __construct(private array $paths) {}
                public function getNamespaces(): array { return array_keys($this->paths); }
                public function getPaths(string $namespace): array { return $this->paths[$namespace] ?? []; }
            }
            final class ChainLoader implements LoaderInterface
            {
                public function __construct(private array $loaders) {}
                public function getLoaders(): array { return $this->loaders; }
            }
            namespace Sylius\Theme;
            final class ThemedTemplateLoader implements \Twig\Loader\LoaderInterface
            {
                public function __construct(private \Twig\Loader\LoaderInterface $decoratedLoader) {}
            }
            namespace Twig;
            final class Environment
            {
                public function __construct(private object $loader) {}
                public function getLoader(): object { return $this->loader; }
            }
            namespace Symfony\Bridge\Twig\Command;
            final class DebugCommand
            {
                public function __construct(private \Twig\Environment $twig) {}
            }
            __CONSOLE_IO__
            namespace App;
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function shutdown(): void {}
            }
            __FRAMEWORK_APPLICATION__
            PHP,
            applicationMembers: <<<'PHP'
    public function has(string $name): bool { return true; }
    public function find(string $name): object
    {
        // the bundle views directory is registered a second time under "!Shop"
        $filesystem = new \Twig\Loader\FilesystemLoader([
            '__main__' => [\dirname(__DIR__).'/templates'],
            'Shop' => [\dirname(__DIR__).'/src/ShopBundle/templates'],
            '!Shop' => [\dirname(__DIR__).'/src/ShopBundle/templates'],
        ]);
        $chain = new \Twig\Loader\ChainLoader([new \Sylius\Theme\ThemedTemplateLoader($filesystem)]);

        return new \Symfony\Bridge\Twig\Command\DebugCommand(new \Twig\Environment($chain));
    }
    public function run(object $input, object $output): int
    {
        // a theme loader hides every filesystem path from debug:twig
        $output->write(json_encode(['globals' => ['app' => []], 'loader_paths' => []], JSON_THROW_ON_ERROR));

        return 0;
    }
PHP,
        ));
    }

    public function writeThemedTwigApplicationWithThemes(): void
    {
        $this->workspace->makeDirectory('templates');
        $this->workspace->write('themes/TestTheme/composer.json', json_encode(['name' => 'acme/test-theme', 'type' => 'sylius-theme'], \JSON_THROW_ON_ERROR));
        $this->workspace->write('themes/TestTheme/templates/shop/home.html.twig', '<p>home</p>');
        $this->workspace->write('themes/TestTheme/templates/bundles/SyliusShopBundle/custom/_promo.html.twig', '<p>promo</p>');
        $this->workspace->makeDirectory('themes/NotATheme/templates');
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            __CONTAINER_BUILDER__
            namespace Twig;
            final class Environment {}
            __CONSOLE_IO__
            namespace App;
            final class ThemeExtension
            {
                public function getAlias(): string { return 'sylius_theme'; }
            }
            final class ThemeBundle
            {
                public function getName(): string { return 'SyliusThemeBundle'; }
                public function getPath(): string { return __DIR__; }
                public function getContainerExtension(): object { return new ThemeExtension(); }
            }
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function shutdown(): void {}
                public function getBundles(): array { return [new ThemeBundle()]; }
            }
            __FRAMEWORK_APPLICATION__
            PHP,
            applicationMembers: <<<'PHP'
    public function has(string $name): bool { return true; }
    public function run(object $input, object $output): int
    {
        // a theme loader hides every filesystem path from debug:twig
        $themes = [
            'enabled' => true,
            'filename' => 'composer.json',
            'scan_depth' => 1,
            'directories' => [\dirname(__DIR__).'/themes'],
        ];
        $result = 'debug:twig' === $input->arguments['command']
            ? ['globals' => ['app' => []], 'loader_paths' => []]
            : [
                'twig' => ['default_path' => \dirname(__DIR__).'/templates', 'paths' => []],
                'sylius_theme' => ['sources' => ['filesystem' => $themes]],
            ];
        $output->write(json_encode($result, JSON_THROW_ON_ERROR));

        return 0;
    }
PHP,
        ));
    }

    public function writeTwigApplicationWithoutDebugCommand(): void
    {
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            namespace Twig;
            final class Environment {}
            namespace App;
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function shutdown(): void {}
            }
            __FRAMEWORK_APPLICATION__
            PHP,
            applicationMembers: <<<'PHP'
    public function has(string $name): bool { return false; }
PHP,
        ));
    }
}
