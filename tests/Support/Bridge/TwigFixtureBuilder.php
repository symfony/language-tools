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
