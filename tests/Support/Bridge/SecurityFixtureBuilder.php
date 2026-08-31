<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class SecurityFixtureBuilder
{
    public function __construct(
        private readonly BridgeFixtureWorkspace $workspace,
        private readonly FakeFrameworkPrelude $prelude = new FakeFrameworkPrelude(),
    ) {
    }

    public function writeSecurityApplication(): void
    {
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            namespace Symfony\Bundle\SecurityBundle;
            final class SecurityBundle {}
            __CONSOLE_IO__
            namespace App;
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function shutdown(): void {}
                public function getBundles(): array { return [new \Symfony\Bundle\SecurityBundle\SecurityBundle()]; }
            }
            __FRAMEWORK_APPLICATION__
            PHP,
            applicationMembers: <<<'PHP'
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
PHP,
        ));
    }

    public function writeUnregisteredSecurityApplication(): void
    {
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            namespace Symfony\Bundle\SecurityBundle;
            final class SecurityBundle {}
            namespace App;
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function getBundles(): array { return []; }
                public function shutdown(): void {}
            }
            PHP,
        ));
    }
}
