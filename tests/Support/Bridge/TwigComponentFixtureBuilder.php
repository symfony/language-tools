<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class TwigComponentFixtureBuilder
{
    public function __construct(
        private readonly BridgeFixtureWorkspace $workspace,
        private readonly FakeFrameworkPrelude $prelude = new FakeFrameworkPrelude(),
    ) {
    }

    public function writeTwigComponentApplication(bool $withUnnameableComponent = false, bool $bundleRegistered = true): void
    {
        $unnameable = $withUnnameableComponent
            ? '"Vendor\\\\Hidden\\\\Component": {"class": "Vendor\\\\Hidden\\\\Component", "tags": [{"name": "twig.component", "parameters": {"expose_public_props": true}}]},'
            : '';
        $source = $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            __CONSOLE_IO__
            namespace Symfony\Component\Console\Exception;
            final class LogicException extends \LogicException
            {
            }
            namespace Symfony\UX\TwigComponent;
            final class ComponentFactory
            {
            }
            final class TwigComponentBundle
            {
                public function getContainerExtension(): object { return new \App\FixtureExtension('twig_component'); }
            }
            namespace App;
            final class FixtureExtension
            {
                public function __construct(private string $alias) {}
                public function getAlias(): string { return $this->alias; }
            }
            final class FrameworkBundle
            {
                public function getContainerExtension(): object { return new FixtureExtension('framework'); }
            }
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function shutdown(): void {}
                public function getBundles(): array { return __BUNDLES__; }
            }
            __FRAMEWORK_APPLICATION__
            PHP,
            applicationMembers: <<<'PHP'
    public function run(object $input, object $output): int
    {
        $output->write("\n ! [NOTE] Some deprecation notice written to the console output.\n\n");
        if ('debug:config' === ($input->arguments['command'] ?? null)) {
            __CONFIG_COMMAND__
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
PHP,
        );
        $this->workspace->write('vendor/autoload.php', str_replace(
            ['__UNNAMEABLE__', '__BUNDLES__', '__CONFIG_COMMAND__'],
            $bundleRegistered
                ? [$unnameable, '[new FrameworkBundle(), new \Symfony\UX\TwigComponent\TwigComponentBundle()]', '']
                : [
                    $unnameable,
                    '[new FrameworkBundle()]',
                    'throw new \Symfony\Component\Console\Exception\LogicException(\'No extensions with configuration available for "twig_component".\');',
                ],
            $source,
        ));
    }
}
