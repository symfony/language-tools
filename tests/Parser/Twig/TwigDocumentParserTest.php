<?php

namespace Symfony\Lsp\Tests\Parser\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TwigDocumentParserTest extends TestCase
{
    #[DataProvider('documentedSyntaxProvider')]
    public function testParsesInlineComments(string $source): void
    {
        self::assertFalse($this->parser()->parse($source)->hasErrors());
    }

    /** @return iterable<string, array{string}> */
    public static function documentedSyntaxProvider(): iterable
    {
        yield 'documentation comment' => [<<<'TWIG'
            {##- The page title. -##}
            {{ title }}
            TWIG];
        yield 'types' => [<<<'TWIG'
            {% types {
                ## The article to display.
                article: 'string',
            } %}
            TWIG];
        yield 'assignment' => [<<<'TWIG'
            {% set
                ## The article to display.
                article = value
            %}
            TWIG];
        yield 'loop' => [<<<'TWIG'
            {% for
                ## The article to display.
                article in articles
            %}
            {% endfor %}
            TWIG];
        yield 'macro' => [<<<'TWIG'
            {% macro card(
                ## The article to display.
                article = null
            ) %}
            {% endmacro %}
            TWIG];
        yield 'output' => [<<<'TWIG'
            {{
                # A regular inline comment.
                value
            }}
            TWIG];
        yield 'hash in string' => ["{{ 'value # not a comment' }}"];
        yield 'documentation comment in string interpolation' => [<<<'TWIG'
            {{ "prefix #{
                ## Documentation in an interpolation.
                value
            } suffix" }}
            TWIG];
        yield 'verbatim content' => [<<<'TWIG'
            {% verbatim %}
                {{ invalid ??? syntax }}
                {% anything unbalanced(
            {% endverbatim %}
            TWIG];
    }

    public function testPreservesUnclosedCommentErrors(): void
    {
        self::assertTrue($this->parser()->parse('{## unclosed')->hasErrors());
    }

    private function parser(): TwigDocumentParser
    {
        return new TwigDocumentParser(
            new NativeTreeSitterParser(new TreeSitterResultDecoder()),
            new TwigCommentParser(),
        );
    }
}
