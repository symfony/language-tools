<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSymbolKind;
use Symfony\Lsp\Feature\DependencyInjection\XmlDependencyInjectionExtractor;

final class XmlDependencyInjectionExtractorTest extends TestCase
{
    public function testExtractsServicesParametersAndReferences(): void
    {
        $facts = (new XmlDependencyInjectionExtractor(new PositionConverter()))->extract('file:///workspace/src/Resources/config/services.xml', <<<'XML'
            <?xml version="1.0" ?>
            <container xmlns="http://symfony.com/schema/dic/services">
                <parameters>
                    <parameter key="shopware.cdn.url">%env(APP_URL)%</parameter>
                    <parameter key='single.parameter'>value</parameter>
                    <!-- <parameter key="commented.parameter">value</parameter> -->
                </parameters>
                <services>
                    <service id="Shopware\Core\Checkout\Cart\CartRuleLoader">
                        <argument type="service" id="Shopware\Core\Content\Rule\RuleLoader"/>
                        <argument type="service" id="logger" on-invalid="ignore"/>
                        <argument>%shopware.cart.expire%</argument>
                        <tag name="kernel.reset" method="reset"/>
                    </service>
                    <service id="cart.alias" alias="Shopware\Core\Checkout\Cart\CartRuleLoader"/>
                    <service id="legacy.loader" class="Shopware\Legacy\Loader" decorates="cart.alias"/>
                    <service id='single.quoted' class='App\SingleQuoted'>
                        <argument type='service' id='single.dependency'/>
                        <argument>%single.parameter%</argument>
                        <tag name='single.tag'/>
                    </service>
                    <!-- <service id="commented.service"><argument type="service" id="commented.reference"/></service> -->
                </services>
            </container>
            XML);

        self::assertNotNull($facts);
        self::assertSame(
            [
                ['Shopware\Core\Checkout\Cart\CartRuleLoader', 'Shopware\Core\Checkout\Cart\CartRuleLoader', null, null, ['kernel.reset']],
                ['cart.alias', null, 'Shopware\Core\Checkout\Cart\CartRuleLoader', null, []],
                ['legacy.loader', 'Shopware\Legacy\Loader', null, 'cart.alias', []],
                ['single.quoted', 'App\SingleQuoted', null, null, ['single.tag']],
            ],
            array_map(
                static fn ($service): array => [$service->id(), $service->className(), $service->alias(), $service->decorates(), $service->tags()],
                $facts->services(),
            ),
        );
        self::assertSame(['shopware.cdn.url', 'single.parameter'], array_map(static fn ($parameter): string => $parameter->name(), $facts->parameters()));
        self::assertSame(
            [
                [DependencyInjectionSymbolKind::Service, 'Shopware\Core\Checkout\Cart\CartRuleLoader', false],
                [DependencyInjectionSymbolKind::Service, 'cart.alias', false],
                [DependencyInjectionSymbolKind::Service, 'Shopware\Core\Content\Rule\RuleLoader', false],
                [DependencyInjectionSymbolKind::Service, 'logger', true],
                [DependencyInjectionSymbolKind::Service, 'single.dependency', false],
                [DependencyInjectionSymbolKind::Parameter, 'shopware.cart.expire', false],
                [DependencyInjectionSymbolKind::Parameter, 'single.parameter', false],
            ],
            array_map(
                static fn ($reference): array => [$reference->kind(), $reference->name(), $reference->isOptional()],
                $facts->references(),
            ),
        );
    }

    public function testIgnoresXmlWithoutTheServicesSchema(): void
    {
        $extractor = new XmlDependencyInjectionExtractor(new PositionConverter());

        self::assertNull($extractor->extract('file:///workspace/phpunit.xml', '<phpunit colors="true"/>'));
    }
}
