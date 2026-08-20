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
use Symfony\Lsp\Project\Project;

final class RouteReferenceExtractorTest extends TestCase
{
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
        $references = (new RouteReferenceExtractor($converter, $parser))->extract($controller, $classIndex);

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
    }
}
