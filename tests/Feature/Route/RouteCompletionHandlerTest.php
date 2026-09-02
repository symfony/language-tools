<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\Route\Route;
use Symfony\Lsp\Feature\Route\RouteCompletionBuilder;
use Symfony\Lsp\Feature\Route\RouteCompletionHandler;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Parser\CommentParserRegistry;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class RouteCompletionHandlerTest extends TestCase
{
    public function testCompletesRouteParameters(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = <<<'PHP'
            <?php
            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    $this->generateUrl('article_show', ['section' => 'news', 's']);
                }
            }
            PHP;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $indexes = new RouteIndexRegistry();
        $indexes->forProject($project)->replace(
            new Route('article_show', '/{section}/article/{slug}', [], [], null, null),
        );
        $converter = new PositionConverter();
        $cursor = strpos($text, "'s']") + 2;
        $position = $converter->toPosition($text, $cursor);
        $handler = $this->handler($documents, $projects, $converter, $indexes);

        self::assertSame(['slug'], array_column($handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]) ?? [], 'label'));
    }

    public function testCompletesParametersFromAllInternationalizedRouteVariants(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = <<<'PHP'
            <?php
            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    $this->generateUrl('app_home', ['locale_']);
                }
            }
            PHP;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $indexes = new RouteIndexRegistry();
        $indexes->forProject($project)->replace(
            new Route('app_home.en', '/en/{locale_en}', [], [], null, null, canonicalName: 'app_home'),
            new Route('app_home.fr', '/fr/{locale_fr}', [], [], null, null, canonicalName: 'app_home'),
        );
        $converter = new PositionConverter();
        $cursor = strpos($text, "locale_']") + \strlen('locale_');
        $position = $converter->toPosition($text, $cursor);
        $handler = $this->handler($documents, $projects, $converter, $indexes);

        self::assertSame(['locale_en', 'locale_fr'], array_column($handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]) ?? [], 'label'));
    }

    #[DataProvider('twigRouteNameCompletionProvider')]
    public function testCompletesRouteNamesInTwigFunctions(string $text): void
    {
        $uri = 'file:///workspace/templates/article.html.twig';
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'twig', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $indexes = new RouteIndexRegistry();
        $indexes->forProject($project)->replace(
            new Route('article_show', '/article/{id}', [], [], null, null),
            new Route('homepage', '/', [], [], null, null),
        );
        $converter = new PositionConverter();
        $cursor = strpos($text, 'article_') + \strlen('article_');
        $position = $converter->toPosition($text, $cursor);
        $handler = $this->handler($documents, $projects, $converter, $indexes);

        self::assertSame(['article_show'], array_column($handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]) ?? [], 'label'));
    }

    #[DataProvider('twigRouteParameterCompletionProvider')]
    public function testCompletesRouteParametersInTwigFunctions(string $text): void
    {
        $uri = 'file:///workspace/templates/article.html.twig';
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'twig', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $indexes = new RouteIndexRegistry();
        $indexes->forProject($project)->replace(
            new Route('article_show', '/{section}/article/{slug}', [], [], null, null),
        );
        $converter = new PositionConverter();
        $cursor = strpos($text, "'s')") + 2;
        $position = $converter->toPosition($text, $cursor);
        $handler = $this->handler($documents, $projects, $converter, $indexes);

        self::assertSame(['slug'], array_column($handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]) ?? [], 'label'));
    }

    public function testCompletesRoutesThroughAProjectControllerBaseClass(): void
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
        $uri = 'file:///workspace/src/Controller/DemoController.php';
        $text = <<<'PHP'
            <?php
            namespace App\Controller;

            final class DemoController extends BaseController
            {
                public function index(): void
                {
                    $this->redirectToRoute('article_');
                }
            }
            PHP;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $indexes = new RouteIndexRegistry();
        $indexes->forProject($project)->replace(new Route('article_show', '/article/{id}', [], [], null, null));
        $converter = new PositionConverter();
        $parser = new TolerantPhpParser(new Parser());
        $classExtractor = new PhpClassDeclarationExtractor($converter, $parser);
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $classIndexes->forProject($project)->replace(
            new DependencyInjectionSourceFacts($baseUri, classes: $classExtractor->extract($baseUri, $base)),
            new DependencyInjectionSourceFacts($uri, classes: $classExtractor->extract($uri, $text)),
        );
        $position = $converter->toPosition($text, strpos($text, 'article_') + \strlen('article_'));
        $handler = $this->handler($documents, $projects, $converter, $indexes, $classIndexes);

        self::assertSame(['article_show'], array_column($handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]) ?? [], 'label'));
    }

    public function testReturnsRouteCompletionWithUtf16TextEdit(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = <<<'PHP'
            <?php
            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    $label = '😀';
                    $this->generateUrl('article_');
                }
            }
            PHP;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $indexes = new RouteIndexRegistry();
        $indexes->forProject($project)->replace(
            new Route('article_edit', '/article/{id}/edit', [], [], null, null),
            new Route('homepage', '/', [], [], null, null),
        );
        $converter = new PositionConverter();
        $cursor = strpos($text, 'article_') + \strlen('article_');
        $position = $converter->toPosition($text, $cursor);
        $handler = $this->handler($documents, $projects, $converter, $indexes);

        self::assertSame([[
            'label' => 'article_edit',
            'kind' => 12,
            'detail' => '/article/{id}/edit',
            'textEdit' => [
                'range' => [
                    'start' => ['line' => 6, 'character' => 28],
                    'end' => ['line' => 6, 'character' => 36],
                ],
                'newText' => 'article_edit',
            ],
        ]], $handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]));
    }

    public function testOffersNoRouteCompletionsInsideTwigComments(): void
    {
        $uri = 'file:///workspace/templates/article.html.twig';
        $documents = new DocumentStore();
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $indexes = new RouteIndexRegistry();
        $indexes->forProject($project)->replace(new Route('article_show', '/article/{slug}', [], [], null, null));
        $converter = new PositionConverter();
        $handler = $this->handler($documents, $projects, $converter, $indexes);

        foreach (["{# {{ path('artic') }} #}", "{# {{ path('article_show', {'s') }} #}"] as $text) {
            $documents->open(new Document($uri, 'twig', 1, $text));
            $cursor = strpos($text, "')");
            self::assertIsInt($cursor);
            $position = $converter->toPosition($text, $cursor);

            self::assertNull($handler->complete([
                'textDocument' => ['uri' => $uri],
                'position' => ['line' => $position->line, 'character' => $position->character],
            ]));
        }
    }

    public function testOffersNoRouteCompletionsInsidePhpComments(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = <<<'PHP'
            <?php
            use Symfony\Component\Routing\RouterInterface;
            class Demo
            {
                public function index(RouterInterface $router): void
                {
                    // $router->generate('artic
                }
            }
            PHP;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $indexes = new RouteIndexRegistry();
        $indexes->forProject($project)->replace(new Route('article_show', '/article/{slug}', [], [], null, null));
        $converter = new PositionConverter();
        $position = $converter->toPosition($text, strpos($text, 'artic') + \strlen('artic'));
        $handler = $this->handler($documents, $projects, $converter, $indexes);

        self::assertNull($handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]));
    }

    /** @return iterable<string, array{string}> */
    public static function twigRouteNameCompletionProvider(): iterable
    {
        yield 'positional' => ["{{ path('article_') }}"];
        yield 'named' => ["{{ path(name: 'article_') }}"];
    }

    /** @return iterable<string, array{string}> */
    public static function twigRouteParameterCompletionProvider(): iterable
    {
        yield 'positional' => ["{{ path('article_show', {'section': 'news', 's') }}"];
        yield 'named' => ["{{ path(name = 'article_show', parameters = {'section': 'news', 's') }}"];
    }

    private function handler(
        DocumentStore $documents,
        ProjectRegistry $projects,
        PositionConverter $converter,
        RouteIndexRegistry $indexes,
        ?DependencyInjectionSourceIndexRegistry $classIndexes = null,
    ): RouteCompletionHandler {
        return new RouteCompletionHandler(
            new DocumentContextResolver($documents, $projects),
            $converter,
            new LspProtocolMapper(),
            $indexes,
            $classIndexes ?? new DependencyInjectionSourceIndexRegistry(),
            RouteReferenceExtractorFactory::create($converter),
            new CommentParserRegistry(['php' => new PhpCommentParser(), 'twig' => new TwigCommentParser()]),
            new RouteCompletionBuilder(),
        );
    }
}
