<?php

namespace Symfony\Lsp\Tests\Parser\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Parser\Twig\TwigStringDecoder;

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

    public function testDecodesStringLiteralsWithTwigEscapeSemantics(): void
    {
        $source = <<<'TWIG'
            {{ single('it\'s a plain value') }}
            {{ single_class('App\Entity\Article') }}
            {{ double("say \"hi\"\twith\x41tab") }}
            TWIG;
        $document = $this->parser()->parse($source);
        $literals = [];
        foreach ($document->nodesOfType('function_call') as $call) {
            $literals[] = $document->firstStringLiteral($call);
        }

        self::assertSame("it's a plain value", $literals[0]?->value);
        self::assertSame("it\\'s a plain value", $literals[0]->raw);
        self::assertSame("'", $literals[0]->quote);
        self::assertSame($literals[0]->raw, substr($source, $literals[0]->startOffset, $literals[0]->endOffset - $literals[0]->startOffset));
        self::assertSame('App\Entity\Article', $literals[1]?->value);
        self::assertSame("say \"hi\"\twithAtab", $literals[2]?->value);
        self::assertSame('"', $literals[2]->quote);
    }

    public function testRejectsInterpolatedStringsAsLiterals(): void
    {
        $document = $this->parser()->parse('{{ call("prefix #{name} suffix") }}');

        foreach ($document->nodesOfType('function_call') as $call) {
            self::assertNull($document->firstStringLiteral($call));
        }
    }

    public function testDecoderMatchesTwigEscapeSemantics(): void
    {
        self::assertSame("it's \\n raw", TwigStringDecoder::decode("it\\'s \\n raw", "'"));
        self::assertSame('App\\Entity\\Article', TwigStringDecoder::decode('App\\Entity\\Article', "'"));
        self::assertSame("tab\thexAoctalA", TwigStringDecoder::decode('tab\\thex\\x41octal\\101', '"'));
        self::assertSame('literal #{brace}', TwigStringDecoder::decode('literal \\#{brace}', '"'));
        self::assertSame('plain', TwigStringDecoder::decode('plain', '"'));
    }

    public function testKeepsOnlyRenderedMarkupInTheMarkupView(): void
    {
        $source = <<<'TWIG'
            <div data-controller="real"><twig:Alert /></div>
            {# <twig:Comment data-controller="comment" /> #}
            {{ '<twig:String data-controller="string" />' }}
            {% set markup = "<twig:Code data-controller='code' />" %}
            {% verbatim %}<twig:Verbatim data-controller="verbatim" />{% endverbatim %}
            {% if enabled %}<twig:Guarded /></div>{% endif %}
            TWIG;

        $markup = $this->parser()->parse($source)->markup();

        self::assertSame(\strlen($source), \strlen($markup));
        self::assertSame(substr_count($source, "\n"), substr_count($markup, "\n"));
        foreach (['real', 'Alert', 'Guarded'] as $rendered) {
            self::assertStringContainsString($rendered, $markup);
        }
        foreach (['comment', 'string', 'code', 'verbatim'] as $hidden) {
            self::assertStringNotContainsString($hidden, $markup);
        }
    }

    public function testKeepsUnrecoverableRegionsReadableInTheMarkupView(): void
    {
        $source = "<div data-controller=\"before\">\n{{ unclosed\n<div data-controller=\"after\">";

        self::assertSame($source, $this->parser()->parse($source)->markup());
    }

    public function testLocatesDirectiveContexts(): void
    {
        $locator = new TwigDirectiveLocator();
        $source = '{{ call("a }} b") }} outside {% set x = [1, {{k: 2}}] %}';

        self::assertTrue($locator->insideDirective($source, (int) strpos($source, '"a')));
        self::assertTrue($locator->insideDirective($source, (int) strpos($source, 'b"')));
        self::assertFalse($locator->insideDirective($source, (int) strpos($source, 'outside')));
        self::assertTrue($locator->insideDirective($source, (int) strpos($source, '2')));
        self::assertFalse($locator->insideDirective($source, \strlen($source)));
        self::assertTrue($locator->insideDirective('{{ unclosed(', 12));
    }

    private function parser(): TwigDocumentParser
    {
        return new TwigDocumentParser(
            new NativeTreeSitterParser(new TreeSitterResultDecoder()),
            new TwigCommentParser(),
        );
    }
}
