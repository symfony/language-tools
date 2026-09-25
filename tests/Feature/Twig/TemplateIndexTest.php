<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Feature\Twig\TemplateDeclaration;
use Symfony\Lsp\Feature\Twig\TemplateIndex;
use Symfony\Lsp\Feature\Twig\TemplateReference;
use Symfony\Lsp\Feature\Twig\TemplateSourceFacts;

final class TemplateIndexTest extends TestCase
{
    public function testSourceDeclarationsWinOverRuntimeDeclarations(): void
    {
        $index = new TemplateIndex(new DependencyInjectionSourceIndex());
        $index->replaceRuntime(true, new TemplateDeclaration('page.html.twig', 'file:///vendor/page.html.twig', $this->range()));
        $source = new TemplateDeclaration('page.html.twig', 'file:///workspace/templates/page.html.twig', $this->range());
        $index->replace(new TemplateSourceFacts($source->uri, $source, []));

        self::assertSame($source, $index->get('page.html.twig'));
        self::assertSame([$source], $index->matching('page'));
    }

    public function testOverlaysReplaceTheSavedFactsOfTheirUriUntilRemoved(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $index = new TemplateIndex(new DependencyInjectionSourceIndex());
        $saved = new TemplateReference('saved.html.twig', $uri, $this->range());
        $index->replace(new TemplateSourceFacts($uri, null, [$saved]));
        $overlaid = new TemplateReference('overlaid.html.twig', $uri, $this->range());
        $index->overlay(new TemplateSourceFacts($uri, null, [$overlaid]));

        self::assertSame([], $index->references('saved.html.twig'));
        self::assertSame([$overlaid], $index->references('overlaid.html.twig'));
        self::assertSame([$overlaid], $index->referencesForUri($uri));

        $index->removeOverlay($uri);

        self::assertSame([$saved], $index->references('saved.html.twig'));
        self::assertSame([$saved], $index->referencesForUri($uri));
    }

    public function testRuntimeTemplateUrisAreReportedIndependentlyOfSources(): void
    {
        $index = new TemplateIndex(new DependencyInjectionSourceIndex());
        $index->replaceRuntime(true, new TemplateDeclaration('page.html.twig', 'file:///vendor/page.html.twig', $this->range()));

        self::assertTrue($index->isComplete());
        self::assertTrue($index->isRuntimeTemplateUri('file:///vendor/page.html.twig'));
        self::assertFalse($index->isRuntimeTemplateUri('file:///workspace/templates/page.html.twig'));
    }

    private function range(): Range
    {
        return new Range(new Position(0, 0), new Position(0, 0));
    }
}
