<?php

namespace Symfony\Lsp\Tests\Parser\TreeSitter;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;

final class NativeTreeSitterParserTest extends TestCase
{
    public function testRecoversAroundIncompleteTwigAndPreservesByteRanges(): void
    {
        $source = "{{ '😀'|trans }}\n{{ path('article_";
        $tree = (new NativeTreeSitterParser())->parse('twig', $source);

        self::assertTrue($tree->hasError());
        $strings = $tree->nodesOfType('string');
        self::assertCount(1, $strings);
        self::assertSame("'😀'", $tree->text($strings[0], $source));
        self::assertSame(3, $strings[0]->startByte());
        self::assertSame(9, $strings[0]->endByte());
    }

    public function testParsesSymfonyYamlTags(): void
    {
        $source = <<<'YAML'
            services:
                App\HandlerCollection:
                    arguments:
                        - !tagged_iterator { tag: app.handler }
                        - !service '@app.locator'
                        - !php/const App\Feature::ENABLED
            YAML;
        $tree = (new NativeTreeSitterParser())->parse('yaml', $source);

        self::assertFalse($tree->hasError());
        self::assertSame(
            ['!tagged_iterator', '!service', '!php/const'],
            array_map(static fn (TreeSitterNode $node): string => $tree->text($node, $source), $tree->nodesOfType('tag')),
        );
    }

    public function testRejectsUnsupportedLanguages(): void
    {
        $this->expectException(\ValueError::class);

        (new NativeTreeSitterParser())->parse('php', '<?php');
    }
}
