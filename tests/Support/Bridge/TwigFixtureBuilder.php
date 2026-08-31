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
        $this->workspace->makeDirectory('templates');
        $this->workspace->makeDirectory('src/ShopBundle/templates');
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
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
            : ['twig' => ['default_path' => \dirname(__DIR__).'/templates', 'paths' => []]];
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
