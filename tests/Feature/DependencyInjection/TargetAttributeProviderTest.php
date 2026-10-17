<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\DependencyInjection\TargetAttributeProvider;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class TargetAttributeProviderTest extends TestCase
{
    private const URI = 'file:///workspace/src/Service.php';

    public function testCompletesTheVariantsOfTheParameterType(): void
    {
        $kit = $this->kit("public function __construct(#[Target('')] LoggerInterface \$logger) {}");

        $items = $kit->get(TargetAttributeProvider::class)->complete($kit->positioned($kit->after(self::URI, "#[Target('")));

        self::assertSame(['assetMapper', 'cache', 'order_subscription.renew'], $kit->labels($items));
        self::assertSame(['monolog.logger.asset_mapper', 'monolog.logger.cache', 'monolog.logger.order_subscription.renew'], array_column($items, 'detail'));
    }

    public function testCompletesVariantsDeclaredInServiceFilesWithoutTheRunningApplication(): void
    {
        $kit = (new ProjectTestKit())
            ->open('file:///workspace/config/services.yaml', "services:\n    Psr\\Log\\LoggerInterface \$audit: '@app.audit_logger'\n")
            ->open(self::URI, "<?php\n\nuse Psr\\Log\\LoggerInterface;\nuse Symfony\\Component\\DependencyInjection\\Attribute\\Target;\n\nfinal class Service\n{\n    public function __construct(#[Target('')] LoggerInterface \$logger) {}\n}\n")
            ->index();

        $items = $kit->get(TargetAttributeProvider::class)->complete($kit->positioned($kit->after(self::URI, "#[Target('")));

        self::assertSame(['audit'], $kit->labels($items));
        self::assertSame(['app.audit_logger'], array_column($items, 'detail'));
    }

    public function testReplacesTheTypedPrefix(): void
    {
        $kit = $this->kit("public function __construct(#[Target(name: 'ca')] ?LoggerInterface \$logger) {}");

        $items = $kit->get(TargetAttributeProvider::class)->complete($kit->positioned($kit->after(self::URI, "'ca")));

        self::assertSame([[
            'label' => 'cache',
            'kind' => 18,
            'detail' => 'monolog.logger.cache',
            'textEdit' => [
                'range' => [
                    'start' => ['line' => 7, 'character' => 48],
                    'end' => ['line' => 7, 'character' => 50],
                ],
                'newText' => 'cache',
            ],
        ]], $items);
    }

    /** @param list<string> $labels */
    #[DataProvider('completionContextProvider')]
    public function testCompletesOnlyTheNameOfATargetAttributeOnASingleTypeParameter(string $method, string $cursorAfter, array $labels): void
    {
        $kit = $this->kit($method);

        $items = $kit->get(TargetAttributeProvider::class)->complete($kit->positioned($kit->after(self::URI, $cursorAfter)));

        self::assertSame($labels, $kit->labels($items));
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function completionContextProvider(): iterable
    {
        yield 'ordinary method parameter' => ["public function run(#[Target('c')] LoggerInterface \$logger): void {}", "'c", ['cache']];
        yield 'union type' => ["public function __construct(#[Target('c')] LoggerInterface|Other \$logger) {}", "'c", []];
        yield 'untyped parameter' => ["public function __construct(#[Target('c')] \$logger) {}", "'c", []];
        yield 'other type' => ["public function __construct(#[Target('c')] Other \$logger) {}", "'c", ['cool']];
        yield 'other attribute' => ["public function __construct(#[\\App\\Target('c')] LoggerInterface \$logger) {}", "'c", []];
        yield 'other argument' => ["public function __construct(#[Autowire('c')] LoggerInterface \$logger) {}", "'c", []];
    }

    #[DataProvider('hoverProvider')]
    public function testHoverShowsTheServiceSymfonyResolvesTheTargetTo(string $name, ?string $expected): void
    {
        $kit = $this->kit("public function __construct(#[Target('{$name}')] LoggerInterface \$logger) {}");

        $hover = $kit->get(TargetAttributeProvider::class)->hover($kit->positioned($kit->inside(self::URI, $name)));

        self::assertSame($expected, null === $hover ? null : $kit->hoverText($hover));
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function hoverProvider(): iterable
    {
        $cache = <<<'MARKDOWN'
            Autowiring alias: `.Psr\Log\LoggerInterface $cache`

            Service: `monolog.logger.cache`

            Class: `Monolog\Logger`
            MARKDOWN;

        yield 'exact name' => ['cache', $cache];
        yield 'service ID' => ['monolog.logger.cache', $cache];
        yield 'camel case form of a target' => ['orderSubscriptionRenew', <<<'MARKDOWN'
            Autowiring alias: `.Psr\Log\LoggerInterface $order_subscription.renew`

            Service: `monolog.logger.order_subscription.renew`
            MARKDOWN];
        yield 'argument alias' => ['assetMapper', <<<'MARKDOWN'
            Autowiring alias: `Psr\Log\LoggerInterface $assetMapper`

            Service: `monolog.logger.asset_mapper`

            Class: `Monolog\Logger`
            MARKDOWN];
        yield 'unknown name' => ['unknown', null];
    }

    private function kit(string $method): ProjectTestKit
    {
        $text = <<<PHP
            <?php

            use Psr\\Log\\LoggerInterface;
            use Symfony\\Component\\DependencyInjection\\Attribute\\Target;

            final class Service
            {
                {$method}
            }
            PHP;

        return (new ProjectTestKit())->open(self::URI, $text)->runtime('container', [
            'servicesComplete' => false,
            'items' => [
                ['id' => 'monolog.logger.cache', 'class' => 'Monolog\\Logger'],
                ['id' => '.Psr\\Log\\LoggerInterface $cache', 'alias' => 'monolog.logger.cache'],
                ['id' => '.Psr\\Log\\LoggerInterface $order_subscription.renew', 'alias' => 'monolog.logger.order_subscription.renew'],
                ['id' => 'monolog.logger.asset_mapper', 'class' => 'Monolog\\Logger'],
                ['id' => 'Psr\\Log\\LoggerInterface $orderSubscriptionRenew', 'alias' => 'monolog.logger.order_subscription.renew'],
                ['id' => 'Psr\\Log\\LoggerInterface $assetMapper', 'alias' => 'monolog.logger.asset_mapper'],
                ['id' => '.Other $cool', 'alias' => 'app.cool'],
            ],
        ]);
    }
}
