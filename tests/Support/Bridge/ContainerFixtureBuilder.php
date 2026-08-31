<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class ContainerFixtureBuilder
{
    public function __construct(
        private readonly BridgeFixtureWorkspace $workspace,
        private readonly FakeFrameworkPrelude $prelude = new FakeFrameworkPrelude(),
    ) {
    }

    public function writeContainerApplication(): void
    {
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
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
PHP,
        ));
    }
}
