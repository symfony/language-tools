<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class RuntimeFrontControllerFixtureBuilder
{
    public function __construct(
        private readonly BridgeFixtureWorkspace $workspace,
        private readonly FakeFrameworkPrelude $prelude = new FakeFrameworkPrelude(),
    ) {
    }

    public function writeRuntimeFrontControllerApplication(): void
    {
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            namespace Symfony\Contracts\EventDispatcher;
            interface EventDispatcherInterface {}
            namespace Symfony\Component\HttpKernel;
            interface KernelInterface {}
            __CONSOLE_IO__
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
            __FRAMEWORK_APPLICATION__
            PHP,
            applicationMembers: <<<'PHP'
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
PHP,
            applicationConstructor: <<<'PHP'
public function __construct(private object $kernel) {}
PHP,
        ));
        $this->workspace->write('composer.json', json_encode([
            'extra' => ['runtime' => [
                'class' => 'Distribution\\ConfiguredRuntime',
                'configured_option' => 'composer',
                'project_dir' => $this->workspace->path,
            ]],
        ], \JSON_THROW_ON_ERROR));
        $this->workspace->write('vendor/autoload_runtime.php', <<<'PHP'
            <?php
            if (true === (require_once __DIR__.'/autoload.php')) {
                return;
            }
            throw new RuntimeException('The runtime must not run when the autoloader is already loaded.');
            PHP);
        $this->workspace->makeDirectory('bin');
        $this->workspace->write('bin/console', <<<'PHP'
            <?php
            require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

            return static function (array $context): object {
                return new \Distribution\ConsoleApplication(new \Distribution\Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']));
            };
            PHP);
    }
}
