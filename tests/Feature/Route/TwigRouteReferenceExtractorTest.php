<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\RouteReference;
use Symfony\Lsp\Feature\Route\TwigRouteReferenceExtractor;

final class TwigRouteReferenceExtractorTest extends TestCase
{
    public function testExtractsPathAndUrlReferencesWithLiteralParameters(): void
    {
        $references = (new TwigRouteReferenceExtractor(new PositionConverter()))->extract(<<<'TWIG'
            <a href="{{ path('article_show', {'id': article.id}) }}">Show</a>
            <a href="{{ url("homepage") }}">Home</a>
            TWIG);

        self::assertSame(['article_show', 'homepage'], array_map(
            static fn (RouteReference $reference): string => $reference->name(),
            $references,
        ));
        self::assertSame(['id'], $references[0]->providedParameters());
        self::assertSame([], $references[1]->providedParameters());
    }
}
