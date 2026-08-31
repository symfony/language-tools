<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class EnvironmentFixtureBuilder
{
    public function __construct(
        private readonly BridgeFixtureWorkspace $workspace,
        private readonly FakeFrameworkPrelude $prelude = new FakeFrameworkPrelude(),
    ) {
    }

    public function writeEnvironmentApplication(): void
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
                public static function getProvidedTypes(): array { return ['json' => 'array', 'int' => 'int']; }
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
    public function run(object $input, object $output): int
    {
        $output->write('{"definitions":[]}');

        return 0;
    }
PHP,
        ));
    }
}
