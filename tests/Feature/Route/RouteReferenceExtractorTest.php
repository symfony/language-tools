<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\Route\RouteReference;
use Symfony\Lsp\Feature\Route\RouteReferenceIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteReferenceLocation;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
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
        $project = new Project('/workspace', 'file:///workspace');
        $classIndex = $classIndexes->forProject($project);
        $classIndex->replace(new DependencyInjectionSourceFacts(
            $uri,
            classes: $classExtractor->extract($uri, $source),
        ));

        self::assertSame([], RouteReferenceExtractorFactory::create($converter, $parser)->extract(new SourceDocument('file:///workspace/source.php', 'php', $source), $classIndex));
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
        $project = new Project('/workspace', 'file:///workspace');
        $classIndex = $classIndexes->forProject($project);
        $classIndex->replace(
            new DependencyInjectionSourceFacts($baseUri, classes: $classExtractor->extract($baseUri, $base)),
            new DependencyInjectionSourceFacts($controllerUri, classes: $classExtractor->extract($controllerUri, $controller)),
        );
        $references = RouteReferenceExtractorFactory::create($converter, $parser)->extract(new SourceDocument('file:///workspace/source.php', 'php', $controller), $classIndex);

        self::assertSame(['article_show'], array_map(static fn ($reference): string => $reference->name, $references));
        self::assertSame('App\\Controller\\DemoController', $references[0]->controllerClass);

        $referenceIndexes = new RouteReferenceIndexRegistry($classIndexes);
        $referenceIndexes->forProject($project)->replace(
            new RouteReferenceLocation('article_show', $controllerUri, new Range(new Position(7, 35), new Position(7, 47)), 'App\\Controller\\DemoController'),
            new RouteReferenceLocation('article_show', 'file:///workspace/src/Unrelated.php', new Range(new Position(1, 0), new Position(1, 12)), 'App\\Unrelated'),
        );

        self::assertSame([$controllerUri], array_map(
            static fn (RouteReferenceLocation $reference): string => $reference->uri,
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
            static fn (RouteReferenceLocation $reference): string => $reference->uri,
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
        $extractor = RouteReferenceExtractorFactory::create($converter);

        $references = $extractor->extract(new SourceDocument('file:///workspace/source.php', 'php', $source));

        self::assertCount(1, $references);
        self::assertSame("it's_a_route", $references[0]->name);
    }

    public function testIgnoresRouteCallsInPhpComments(): void
    {
        $source = <<<'PHP'
            <?php
            class DemoController extends \Symfony\Bundle\FrameworkBundle\Controller\AbstractController
            {
                public function index(): void
                {
                    // $this->generateUrl('commented_route');
                    /* $this->redirectToRoute('blocked_route'); */
                    $this->generateUrl('live_route');
                }
            }
            PHP;
        $converter = new PositionConverter();
        $extractor = RouteReferenceExtractorFactory::create($converter);

        self::assertSame(['live_route'], array_map(static fn (RouteReference $reference): string => $reference->name, $extractor->extract(new SourceDocument('file:///workspace/source.php', 'php', $source))));
    }

    #[DataProvider('receiverProvider')]
    public function testRecognizesOnlySymfonyRouterReceivers(bool $supported, string $body): void
    {
        $source = <<<PHP
            <?php
            namespace App\Service;

            use App\Routing\MyRouterInterface;
            use App\Routing\RouterInterface as ApplicationRouter;
            use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
            use Symfony\Component\Routing\RouterInterface;

            final class Notifier
            {
            {$body}
            }
            PHP;
        $converter = new PositionConverter();
        $extractor = RouteReferenceExtractorFactory::create($converter);

        self::assertSame(
            $supported ? ['article_show'] : [],
            array_map(static fn (RouteReference $reference): string => $reference->name, $extractor->extract(new SourceDocument('file:///workspace/source.php', 'php', $source))),
        );
    }

    /** @return iterable<string, array{bool, string}> */
    public static function receiverProvider(): iterable
    {
        yield 'router parameter' => [true, <<<'PHP'
                public function notify(RouterInterface $router): void
                {
                    $router->generate('article_show');
                }
            PHP];
        yield 'url generator parameter' => [true, <<<'PHP'
                public function notify(UrlGeneratorInterface $urls): void
                {
                    $urls->generate('article_show');
                }
            PHP];
        yield 'fully qualified parameter' => [true, <<<'PHP'
                public function notify(\Symfony\Component\Routing\RouterInterface $router): void
                {
                    $router->generate('article_show');
                }
            PHP];
        yield 'promoted router property' => [true, <<<'PHP'
                public function __construct(private readonly RouterInterface $router)
                {
                }

                public function notify(): void
                {
                    $this->router->generate('article_show');
                }
            PHP];
        yield 'router property' => [true, <<<'PHP'
                private UrlGeneratorInterface $urls;

                public function notify(): void
                {
                    $this->urls->generate('article_show');
                }
            PHP];
        yield 'captured router parameter' => [true, <<<'PHP'
                public function notify(RouterInterface $router): callable
                {
                    return function () use ($router): string {
                        return $router->generate('article_show');
                    };
                }
            PHP];
        yield 'suffixed application interface' => [false, <<<'PHP'
                public function notify(MyRouterInterface $router): void
                {
                    $router->generate('article_show');
                }
            PHP];
        yield 'aliased application interface' => [false, <<<'PHP'
                public function notify(ApplicationRouter $router): void
                {
                    $router->generate('article_show');
                }
            PHP];
        yield 'untyped parameter' => [false, <<<'PHP'
                public function notify($router): void
                {
                    $router->generate('article_show');
                }
            PHP];
        yield 'router typed in another scope' => [false, <<<'PHP'
                public function inject(RouterInterface $router): void
                {
                }

                public function notify($router): void
                {
                    $router->generate('article_show');
                }
            PHP];
        yield 'chained receiver' => [false, <<<'PHP'
                public function notify(RouterInterface $router): void
                {
                    $router->getGenerator()->generate('article_show');
                }
            PHP];
    }

    /** @param list<string>|null $expected */
    #[DataProvider('parameterProvider')]
    public function testExtractsConservativeLiteralParameterKeys(?array $expected, string $afterRouteName): void
    {
        $source = <<<PHP
            <?php
            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    \$this->generateUrl('article_show'{$afterRouteName};
                }
            }
            PHP;
        $references = RouteReferenceExtractorFactory::create(new PositionConverter())->extract(new SourceDocument('file:///workspace/source.php', 'php', $source));

        self::assertCount(1, $references);
        self::assertSame($expected, $references[0]->providedParameters);
    }

    /** @return iterable<string, array{list<string>|null, string}> */
    public static function parameterProvider(): iterable
    {
        yield 'omitted parameter list' => [[], ')'];
        yield 'empty parameter list' => [[], ', [])'];
        yield 'reference type argument' => [[], ', [], UrlGeneratorInterface::ABSOLUTE_URL)'];
        yield 'named reference type argument' => [null, ', referenceType: 1)'];
        yield 'named parameter list' => [null, ', parameters: [])'];
        yield 'unpacked arguments' => [null, ', ...$arguments)'];
        yield 'dynamic parameter argument' => [null, ', $parameters)'];
        yield 'legacy array syntax' => [null, ", array('id' => 1))"];
        yield 'top-level unpacking' => [null, ', [...$parameters])'];
        yield 'top-level unpacking after a literal key' => [null, ", ['locale' => 'en', ...\$parameters])"];
        yield 'variable top-level key' => [null, ', [$key => 1])'];
        yield 'constant top-level key' => [null, ', [PARAMETER => 1])'];
        yield 'concatenated top-level key' => [null, ", ['i'.'d' => 1])"];
        yield 'interpolated top-level key' => [null, ', ["{$prefix}id" => 1])'];
        yield 'called top-level key' => [null, ', [parameter() => 1])'];
        yield 'nested dynamic key' => [['query'], ", ['query' => [\$key => 1]])"];
        yield 'unkeyed top-level value' => [[], ', [parameter()])'];
        yield 'nested argument unpacking' => [['id'], ", ['id' => sprintf(...\$parts)])"];
        yield 'nested array unpacking' => [['id', 'query'], ", ['id' => '1', 'query' => ['locale' => 'fr', ...\$parts]])"];
        yield 'balanced brackets inside strings' => [['id', 'slug'], ", ['id' => [']'], 'slug' => \"a\\\"b]\"])"];
        yield 'duplicate keys' => [['id'], ", ['id' => 1, 'id' => 2])"];
        yield 'unbalanced parameter array' => [null, ", ['id' => 1"];
        yield 'unexpected expression after parameter array' => [null, ", ['id' => 1] + [])"];

        foreach ([
            'escaped single quote' => "'it\\'s'",
            'escaped single backslash' => "'\\\\'",
            'escaped backslash' => '"\\\\"',
            'escaped quote' => '"\""',
            'escaped dollar' => '"\$"',
            'newline' => '"\n"',
            'carriage return' => '"\r"',
            'tab' => '"\t"',
            'vertical tab' => '"\v"',
            'escape' => '"\e"',
            'form feed' => '"\f"',
            'octal' => '"\101"',
            'two-digit hexadecimal' => '"\x64"',
            'one-digit hexadecimal' => '"\x4"',
            'ASCII Unicode' => '"i\u{64}"',
            'two-byte Unicode' => '"\u{80}"',
            'three-byte Unicode' => '"\u{800}"',
            'four-byte Unicode' => '"\u{1F600}"',
            'maximum Unicode codepoint' => '"\u{10FFFF}"',
            'unrecognized escape' => '"\d"',
            'hexadecimal without digits' => '"\xG"',
        ] as $name => $literal) {
            yield $name => [[self::evaluatePhpStringLiteral($literal)], ", [{$literal} => 1])"];
        }
    }

    #[DataProvider('cursorProvider')]
    public function testRecognizesRouteCallsBeingTyped(bool $supported, string $source): void
    {
        $cursor = strpos($source, '|');
        self::assertIsInt($cursor);

        self::assertSame($supported, RouteReferenceExtractorFactory::create(new PositionConverter())->supportsRouteCallAt(str_replace('|', '', $source), $cursor));
    }

    /** @return iterable<string, array{bool, string}> */
    public static function cursorProvider(): iterable
    {
        yield 'controller helper' => [true, <<<'PHP'
            <?php
            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    $this->generateUrl('article_|
                }
            }
            PHP];
        yield 'router parameter' => [true, <<<'PHP'
            <?php
            use Symfony\Component\Routing\RouterInterface;

            function notify(RouterInterface $router): void
            {
                $router->generate('article_|
            }
            PHP];
        yield 'suffixed application interface' => [false, <<<'PHP'
            <?php
            use App\Routing\MyRouterInterface;

            function notify(MyRouterInterface $router): void
            {
                $router->generate('article_|
            }
            PHP];
        yield 'unknown receiver' => [false, <<<'PHP'
            <?php
            $unknown->generateUrl('article_|
            PHP];
        yield 'outside a route call' => [false, <<<'PHP'
            <?php
            use Symfony\Component\Routing\RouterInterface;

            function notify(RouterInterface $router): void
            {
                $router->generate('article_show');
                $name = 'a|';
            }
            PHP];
    }

    private static function evaluatePhpStringLiteral(string $literal): string
    {
        $value = @eval('return '.$literal.';');
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('Expected PHP to evaluate %s as a string.', $literal));
        }

        return $value;
    }
}
