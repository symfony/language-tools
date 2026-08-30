<?php

namespace Symfony\Lsp\Tests\Feature\Configuration;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Feature\Configuration\ConfigurationIndex;
use Symfony\Lsp\Feature\Configuration\ConfigurationNode;
use Symfony\Lsp\Feature\Configuration\ConfigurationPathResolver;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;

final class ConfigurationPathResolverTest extends TestCase
{
    public function testResolvesPhpDslPaths(): void
    {
        $resolver = new ConfigurationPathResolver(new PhpCommentParser(), new XmlCommentParser());
        $index = $this->index();
        $text = '<?php function configure(FrameworkConfig $options) { $options->router()->utf8(true); }';
        $document = new Document('file:///workspace/config/framework.php', 'php', 1, $text);

        self::assertSame('framework', $resolver->phpRoot($text, 'options'));
        self::assertSame('some_option', $resolver->phpMethodName('someOption'));
        self::assertSame(['framework', 'router', 'utf8'], $resolver->resolvePhpNode($document, $index, strpos($text, 'utf8') + 1)[0] ?? null);
    }

    public function testResolvesXmlDslPaths(): void
    {
        $resolver = new ConfigurationPathResolver(new PhpCommentParser(), new XmlCommentParser());
        $index = $this->index();
        $text = '<container><framework:config><framework:router><framework:utf8/></framework:router></framework:config></container>';
        $document = new Document('file:///workspace/config/framework.xml', 'xml', 1, $text);

        self::assertSame(['framework', 'router'], $resolver->xmlPath('<container><framework:config><framework:router>', $index));
        self::assertSame(['framework', 'router', 'utf8'], $resolver->resolveXmlNode($document, $index, strpos($text, 'framework:utf8') + 11)[0] ?? null);
        self::assertNull($resolver->xmlElementPath(['framework'], 'other:router', $index));
    }

    private function index(): ConfigurationIndex
    {
        $index = new ConfigurationIndex();
        $index->replace(['framework' => $this->node('framework', [
            $this->node('router', [
                $this->node('utf8'),
            ]),
        ])]);

        return $index;
    }

    /** @param list<ConfigurationNode> $children */
    private function node(string $name, array $children = []): ConfigurationNode
    {
        return new ConfigurationNode($name, [] === $children ? 'boolean' : 'array', false, false, null, null, null, false, [], $children, null);
    }
}
