<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class EventFixtureBuilder
{
    public function __construct(
        private readonly BridgeFixtureWorkspace $workspace,
        private readonly FakeFrameworkPrelude $prelude = new FakeFrameworkPrelude(),
    ) {
    }

    public function writeEventApplication(): void
    {
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            namespace Symfony\Contracts\EventDispatcher;
            interface EventDispatcherInterface {}
            namespace Symfony\Component\EventDispatcher;
            interface EventSubscriberInterface
            {
                public static function getSubscribedEvents(): array;
            }
            __CONSOLE_IO__
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
            __FRAMEWORK_APPLICATION__
            PHP,
            applicationMembers: <<<'PHP'
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
PHP,
        ));
    }
}
