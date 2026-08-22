<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\Route\RouteReferenceExtractor;
use Symfony\Lsp\Feature\Route\RouteReferenceIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteReferenceLocation;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\QuotedArgumentMatcher;
use Symfony\Lsp\Project\Project;

final class RouteReferenceExtractorTest extends TestCase
{
    public function testRejectsAnUnrelatedProjectClassNamedAbstractController(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $source = <<<'PHP'
            <?php
            class AbstractController
            {
            }

            class BaseController extends AbstractController
            {
            }

            class DemoController extends BaseController
            {
                public function index(): void
                {
                    $this->redirectToRoute('not_a_symfony_route');
                }
            }
            PHP;
        $converter = new PositionConverter();
        $parser = new TolerantPhpParser(new Parser());
        $classExtractor = new PhpClassDeclarationExtractor($converter, $parser);
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $classIndex = $classIndexes->forProject($project);
        $classIndex->replace(new DependencyInjectionSourceFacts(
            $uri,
            classes: $classExtractor->extract($uri, $source),
        ));

        self::assertSame([], (new RouteReferenceExtractor($converter, $parser, new QuotedArgumentMatcher($converter)))->extract($source, $classIndex));
    }

    public function testRecognizesControllerReferencesThroughProjectBaseClasses(): void
    {
        $baseUri = 'file:///workspace/src/Controller/BaseController.php';
        $base = <<<'PHP'
            <?php
            namespace App\Controller;

            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

            abstract class BaseController extends AbstractController
            {
            }
            PHP;
        $controllerUri = 'file:///workspace/src/Controller/DemoController.php';
        $controller = <<<'PHP'
            <?php
            namespace App\Controller;

            final class DemoController extends BaseController
            {
                public function index(): void
                {
                    $this->redirectToRoute('article_show');
                }
            }

            final class Unrelated
            {
                public function index(): void
                {
                    $this->redirectToRoute('ignored');
                }
            }
            PHP;
        $converter = new PositionConverter();
        $parser = new TolerantPhpParser(new Parser());
        $classExtractor = new PhpClassDeclarationExtractor($converter, $parser);
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $classIndex = $classIndexes->forProject($project);
        $classIndex->replace(
            new DependencyInjectionSourceFacts($baseUri, classes: $classExtractor->extract($baseUri, $base)),
            new DependencyInjectionSourceFacts($controllerUri, classes: $classExtractor->extract($controllerUri, $controller)),
        );
        $references = (new RouteReferenceExtractor($converter, $parser, new QuotedArgumentMatcher($converter)))->extract($controller, $classIndex);

        self::assertSame(['article_show'], array_map(static fn ($reference): string => $reference->name(), $references));
        self::assertSame('App\\Controller\\DemoController', $references[0]->controllerClass());

        $referenceIndexes = new RouteReferenceIndexRegistry($classIndexes);
        $referenceIndexes->forProject($project)->replace(
            new RouteReferenceLocation('article_show', $controllerUri, new Range(new Position(7, 35), new Position(7, 47)), 'App\\Controller\\DemoController'),
            new RouteReferenceLocation('article_show', 'file:///workspace/src/Unrelated.php', new Range(new Position(1, 0), new Position(1, 12)), 'App\\Unrelated'),
        );

        self::assertSame([$controllerUri], array_map(
            static fn (RouteReferenceLocation $reference): string => $reference->uri(),
            $referenceIndexes->forProject($project)->find('article_show'),
        ));

        $unrelatedBase = '<?php namespace App\\Controller; abstract class BaseController {}';
        $classIndex->overlay(new DependencyInjectionSourceFacts(
            $baseUri,
            classes: $classExtractor->extract($baseUri, $unrelatedBase),
        ));
        self::assertSame([], $referenceIndexes->forProject($project)->find('article_show'));

        $classIndex->removeOverlay($baseUri);
        /** @var list<RouteReferenceLocation> $restoredReferences */
        $restoredReferences = $referenceIndexes->forProject($project)->find('article_show');
        self::assertSame([$controllerUri], array_map(
            static fn (RouteReferenceLocation $reference): string => $reference->uri(),
            $restoredReferences,
        ));
    }

    public function testDecodesRouteNamesWithEscapedQuotes(): void
    {
        $source = <<<'PHP'
            <?php
            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    $this->redirectToRoute('it\'s_a_route');
                }
            }
            PHP;
        $converter = new PositionConverter();
        $extractor = new RouteReferenceExtractor($converter, new TolerantPhpParser(new Parser()), new QuotedArgumentMatcher($converter));

        $references = $extractor->extract($source);

        self::assertCount(1, $references);
        self::assertSame("it's_a_route", $references[0]->name());
    }
}
