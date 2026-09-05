<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSymbolKind;
use Symfony\Lsp\Feature\DependencyInjection\XmlDependencyInjectionExtractor;
use Symfony\Lsp\Parser\Xml\TolerantXmlParser;
use Symfony\Lsp\Parser\Xml\XmlDocument;
use Symfony\Lsp\Parser\Xml\XmlParserInterface;

final class XmlDependencyInjectionExtractorTest extends TestCase
{
    public function testExtractsServicesParametersAndReferences(): void
    {
        $facts = $this->extractor()->extract('file:///workspace/src/Resources/config/services.xml', <<<'XML'
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
                static fn ($service): array => [$service->id, $service->className, $service->alias, $service->decorates, $service->tags],
                $facts->services,
            ),
        );
        self::assertSame(['shopware.cdn.url', 'single.parameter'], array_map(static fn ($parameter): string => $parameter->name, $facts->parameters));
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
                static fn ($reference): array => [$reference->kind, $reference->name, $reference->optional],
                $facts->references,
            ),
        );
    }

    public function testUsesExactMarkupAndParentRelationships(): void
    {
        $text = <<<'XML'
            <!DOCTYPE container [
                <!ENTITY declared "declared.service">
                <!ENTITY external SYSTEM "file:///etc/passwd">
            ]>
            <?ignored <service id="pi.service"/>?>
            <container xmlns="http://symfony.com/schema/dic/services">
                <services>
                    <service data-id="wrong" x:id="also.wrong" id="real > service" marker="<!-- literal -->">
                        <![CDATA[<tag name="cdata.tag"/><argument type="service" id="cdata.reference"/>]]>
                        <service id="nested"><tag name="nested.tag"/></service>
                        <tag name="outer.tag"/>
                    </service>
                    <broken value="unfinished
                    <service id="recovered" alias="&declared;"/>
                </services>
                <!-- <service id="commented"/> -->
            </container>
            XML;
        $facts = $this->extractor()->extract('file:///workspace/config/services.xml', $text);

        self::assertNotNull($facts);
        self::assertSame(
            [
                ['real > service', ['outer.tag']],
                ['nested', ['nested.tag']],
                ['recovered', []],
            ],
            array_map(static fn ($service): array => [$service->id, $service->tags], $facts->services),
        );
        self::assertSame(['&declared;'], array_map(static fn ($reference): string => $reference->name, $facts->references));
        self::assertSame([], $facts->parameters);

        $converter = new PositionConverter();
        $start = $converter->toByteOffset($text, $facts->services[0]->range->start);
        $end = $converter->toByteOffset($text, $facts->services[0]->range->end);
        self::assertSame('real > service', substr($text, $start, $end - $start));
    }

    public function testDoesNotParseXmlWithoutTheServicesSchemaMarker(): void
    {
        $parser = new CountingXmlParser();
        $extractor = new XmlDependencyInjectionExtractor(new PositionConverter(), $parser);

        self::assertNull($extractor->extract('file:///workspace/phpunit.xml', '<phpunit colors="true"/>'));
        self::assertSame(0, $parser->calls);
    }

    public function testRejectsSchemaMarkersOutsideNamespaceAttributes(): void
    {
        $extractor = $this->extractor();

        self::assertNull($extractor->extract('file:///workspace/comment.xml', '<!-- http://symfony.com/schema/dic/services --><root/>'));
        self::assertNull($extractor->extract('file:///workspace/entity.xml', '<!DOCTYPE root [<!ENTITY schema "http://symfony.com/schema/dic/services">]><root xmlns="&schema;"/>'));
    }

    private function extractor(): XmlDependencyInjectionExtractor
    {
        return new XmlDependencyInjectionExtractor(new PositionConverter(), new TolerantXmlParser());
    }
}

final class CountingXmlParser implements XmlParserInterface
{
    public int $calls = 0;

    public function parse(string $source): XmlDocument
    {
        ++$this->calls;

        return new XmlDocument([]);
    }
}
