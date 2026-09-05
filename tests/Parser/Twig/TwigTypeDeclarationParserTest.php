<?php

namespace Symfony\Lsp\Tests\Parser\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigTypeDeclaration;
use Symfony\Lsp\Parser\Twig\TwigTypeDeclarationParser;

final class TwigTypeDeclarationParserTest extends TestCase
{
    public function testParsesDocumentedTypeDeclarationsAndRecoversAroundIncompleteEntries(): void
    {
        $declarations = (new TwigTypeDeclarationParser(new TwigCommentParser()))->parse(<<<'TWIG'
            {## Documentation for the types tag. #}
            {% types {
                ## The article to display.
                ## Includes its author and publication date.
                article: 'App\\Entity\\Article',

                # This regular comment is not documentation.
                featured?: 'boolean',
                future: 'App\Entity\Article',
                incomplete,

                ## The current page.
                page?: 'positive-int',
            } %}
            {% types title: 'string' %}
            {% verbatim %}
                {% types ignored: 'never' %}
            {% endverbatim %}
            TWIG);

        self::assertSame([
            ['article', 'App\Entity\Article', false, "The article to display.\nIncludes its author and publication date."],
            ['featured', 'boolean', true, null],
            ['future', 'App\Entity\Article', false, null],
            ['page', 'positive-int', true, 'The current page.'],
            ['title', 'string', false, null],
        ], array_map(
            static fn (TwigTypeDeclaration $declaration): array => [
                $declaration->name,
                $declaration->type,
                $declaration->optional,
                $declaration->documentation,
            ],
            $declarations,
        ));
    }

    public function testDecodesTypeLiteralsWithTwigQuoteSemantics(): void
    {
        $declarations = (new TwigTypeDeclarationParser(new TwigCommentParser()))->parse(<<<'TWIG'
            {% types {
                namespaced: 'App\Entity\numbers\Rate',
                escaped: 'it\'s a \\ type',
                interpreted: "tab\thex\x41octal\101",
            } %}
            TWIG);

        self::assertSame([
            ['namespaced', 'App\Entity\numbers\Rate'],
            ['escaped', 'it\'s a \ type'],
            ['interpreted', "tab\thexAoctalA"],
        ], array_map(
            static fn (TwigTypeDeclaration $declaration): array => [$declaration->name, $declaration->type],
            $declarations,
        ));
    }

    public function testRecoversAroundUnclosedDirectives(): void
    {
        $parser = new TwigTypeDeclarationParser(new TwigCommentParser());

        self::assertSame(['title'], array_map(
            static fn (TwigTypeDeclaration $declaration): string => $declaration->name,
            $parser->parse("{{ unfinished\n{% types title: 'string' %}"),
        ));
        self::assertSame(['title'], array_map(
            static fn (TwigTypeDeclaration $declaration): string => $declaration->name,
            $parser->parse("{% types { title: 'string'"),
        ));
    }
}
