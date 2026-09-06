<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclaration;
use Symfony\Lsp\Feature\Route\RouteControllerClassifier;
use Symfony\Lsp\Feature\Route\RouteDeclaration;
use Symfony\Lsp\Feature\Route\RouteReference;
use Symfony\Lsp\Feature\Route\RouteSourceFacts;
use Symfony\Lsp\Feature\Route\RouteSourceIndex;

final class RouteSourceIndexTest extends TestCase
{
    public function testOverlayAtomicallyShadowsAndRestoresFacts(): void
    {
        $firstUri = 'file:///first.php';
        $secondUri = 'file:///second.php';
        $savedFirst = $this->facts($firstUri, 'shared', 1);
        $savedSecond = $this->facts($secondUri, 'shared', 2);
        $overlayFirst = $this->facts($firstUri, 'shared', 3);
        $index = $this->index();
        $index->replace($savedFirst, $savedSecond);

        self::assertSame([1, 2], $this->declarationLines($index, 'shared'));
        self::assertSame([1, 2], $this->referenceLines($index, 'shared'));

        $index->overlay($overlayFirst);

        self::assertSame($overlayFirst, $index->factsForUri($firstUri));
        self::assertSame([2, 3], $this->declarationLines($index, 'shared'));
        self::assertSame([2, 3], $this->referenceLines($index, 'shared'));
        self::assertSame([3], array_map(
            static fn (RouteReference $reference): int => $reference->range->start->line,
            $index->referencesForUri($firstUri),
        ));

        $index->removeOverlay($firstUri);

        self::assertSame($savedFirst, $index->factsForUri($firstUri));
        self::assertSame([1, 2], $this->declarationLines($index, 'shared'));
        self::assertSame([1, 2], $this->referenceLines($index, 'shared'));
    }

    public function testSourceReplacementAndRemovalUpdateDeclarationsAndReferencesTogether(): void
    {
        $uri = 'file:///source.php';
        $index = $this->index();
        $index->replace($this->facts($uri, 'old', 1));

        $index->replaceSource($this->facts($uri, 'new', 2));

        self::assertSame([], $index->declarations('old'));
        self::assertSame([], $index->references('old'));
        self::assertSame([2], $this->declarationLines($index, 'new'));
        self::assertSame([2], $this->referenceLines($index, 'new'));

        $index->removeSource($uri);

        self::assertSame([], $index->declarations('new'));
        self::assertSame([], $index->references('new'));
        self::assertSame([], $index->referencesForUri($uri));
    }

    public function testControllerFilteringTracksTheCurrentDependencyInjectionHierarchy(): void
    {
        $baseUri = 'file:///BaseController.php';
        $controllerUri = 'file:///Controller.php';
        $range = $this->range(1);
        $classIndex = new DependencyInjectionSourceIndex();
        $classIndex->replace(
            new DependencyInjectionSourceFacts($baseUri, classes: [
                new PhpClassDeclaration(
                    'App\\BaseController',
                    $baseUri,
                    $range,
                    'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController',
                ),
            ]),
            new DependencyInjectionSourceFacts($controllerUri, classes: [
                new PhpClassDeclaration('App\\Controller', $controllerUri, $range, 'App\\BaseController'),
            ]),
        );
        $index = new RouteSourceIndex($classIndex, new RouteControllerClassifier());
        $index->replace(new RouteSourceFacts($controllerUri, [], [
            new RouteReference('route', $controllerUri, $range, 'App\\Controller'),
        ]));

        self::assertCount(1, $index->references('route'));
        self::assertCount(1, $index->referencesForUri($controllerUri));

        $classIndex->overlay(new DependencyInjectionSourceFacts($baseUri, classes: [
            new PhpClassDeclaration('App\\BaseController', $baseUri, $range),
        ]));

        self::assertSame([], $index->references('route'));
        self::assertSame([], $index->referencesForUri($controllerUri));

        $classIndex->removeOverlay($baseUri);

        self::assertCount(1, $index->references('route'));
        self::assertCount(1, $index->referencesForUri($controllerUri));
    }

    private function facts(string $uri, string $name, int $line): RouteSourceFacts
    {
        $range = $this->range($line);

        return new RouteSourceFacts(
            $uri,
            [new RouteDeclaration($name, $uri, $range)],
            [new RouteReference($name, $uri, $range)],
        );
    }

    private function index(): RouteSourceIndex
    {
        return new RouteSourceIndex(new DependencyInjectionSourceIndex(), new RouteControllerClassifier());
    }

    /** @return list<int> */
    private function declarationLines(RouteSourceIndex $index, string $name): array
    {
        return array_map(
            static fn (RouteDeclaration $declaration): int => $declaration->range->start->line,
            $index->declarations($name),
        );
    }

    /** @return list<int> */
    private function referenceLines(RouteSourceIndex $index, string $name): array
    {
        return array_map(
            static fn (RouteReference $reference): int => $reference->range->start->line,
            $index->references($name),
        );
    }

    private function range(int $line): Range
    {
        return new Range(new Position($line, 0), new Position($line, 1));
    }
}
