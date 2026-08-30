<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\RouteReference;
use Symfony\Lsp\Feature\Route\TwigRouteReferenceExtractor;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TwigRouteReferenceExtractorTest extends TestCase
{
    public function testExtractsPathAndUrlReferencesWithLiteralParameters(): void
    {
        $references = (new TwigRouteReferenceExtractor(new PositionConverter(), new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser())))->extract(<<<'TWIG'
            {# {{ path('ignored') }} #}
            {## {{ path('documented_outer') }} ##}
            {% types {
                ## path('documented')
                article: 'string',
            } %}
            {{ "#{
                ## path('interpolation_documented')
                value
            }" }}
            {%- verbatim -%}
                {{ path('verbatim_ignored') }}
            {%- endverbatim -%}
            {{ path(route_name('ignored')) }}
            <a href="{{ path('article_show', {'id': article.id}) }}">Show</a>
            <a href="{{ url("homepage") }}">Home</a>
            {{ path('unfinished'
            TWIG);

        self::assertSame(['article_show', 'homepage'], array_map(
            static fn (RouteReference $reference): string => $reference->name,
            $references,
        ));
        self::assertSame(['id'], $references[0]->providedParameters);
        self::assertSame([], $references[1]->providedParameters);
    }

    public function testExtractsShorthandMappingParametersConservatively(): void
    {
        $references = (new TwigRouteReferenceExtractor(new PositionConverter(), new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser())))->extract(<<<'TWIG'
            {{ url('blog_archives', {year, month}) }}
            {{ path('blog_new_in_symfony', { version }) }}
            {{ path('legacy_doc', { version, section, page: slug, locale, orm}) }}
            {{ path('dynamic_argument', parameters) }}
            {{ path('dynamic_key', {(parameter): value}) }}
            {{ path('dynamic_spread', { version, ...parameters}) }}
            TWIG);

        self::assertSame(
            [
                ['year', 'month'],
                ['version'],
                ['version', 'section', 'page', 'locale', 'orm'],
                null,
                null,
                null,
            ],
            array_map(static fn (RouteReference $reference): ?array => $reference->providedParameters, $references),
        );
    }

    public function testIgnoresUnclosedVerbatimContent(): void
    {
        $references = (new TwigRouteReferenceExtractor(new PositionConverter(), new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser())))->extract(<<<'TWIG'
            {% verbatim %}
                {{ path('ignored') }}
            TWIG);

        self::assertSame([], $references);
    }
}
