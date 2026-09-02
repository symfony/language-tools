<?php

namespace Symfony\Lsp\Tests\Index;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\PhpParseHealthResolver;
use Symfony\Lsp\Index\SourceOverlayHealthRegistry;
use Symfony\Lsp\Index\SourceParseHealth;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Project\Project;

final class PhpParseHealthResolverTest extends TestCase
{
    #[DataProvider('healthyPhpProvider')]
    public function testRecognizesHealthyModernPhp(string $source): void
    {
        $registry = new SourceOverlayHealthRegistry();
        $resolver = new PhpParseHealthResolver(new TolerantPhpParser(new Parser()), $registry);
        $project = new Project('/workspace', 'file:///workspace');
        $document = new Document('file:///workspace/src/Article.php', 'php', 1, $source);

        self::assertSame(SourceParseHealth::Healthy, $resolver->resolve($project, $document));
        self::assertFalse($registry->isDegraded($document->uri));
    }

    /** @return iterable<string, array{string}> */
    public static function healthyPhpProvider(): iterable
    {
        yield 'PHP 8.4 property hooks and asymmetric visibility' => [<<<'PHP'
            <?php
            final class Article
            {
                public private(set) string $title {
                    get => $this->title;
                    set => trim($value);
                }
            }
            PHP];
        yield 'promoted property hooks' => [<<<'PHP'
            <?php
            final class Article
            {
                public function __construct(public string $title { set(string $value) { $this->title = trim($value); } })
                {
                }
            }
            PHP];
        yield 'by-reference get hooks' => [<<<'PHP'
            <?php
            final class Article
            {
                public array $tags {
                    &get { return $this->tags; }
                }
            }
            PHP];
        yield 'PHP 8.5 pipe operator' => [<<<'PHP'
            <?php
            $title = ' Symfony ' |> trim(...) |> strtoupper(...);
            PHP];
    }

    public function testMarksInvalidPhpAsPartialUntilAHealthyVersionArrives(): void
    {
        $registry = new SourceOverlayHealthRegistry();
        $resolver = new PhpParseHealthResolver(new TolerantPhpParser(new Parser()), $registry);
        $project = new Project('/workspace', 'file:///workspace');
        $uri = 'file:///workspace/src/Article.php';

        self::assertSame(
            SourceParseHealth::Partial,
            $resolver->resolve($project, new Document($uri, 'php', 1, '<?php final class Article { public function title(')),
        );
        self::assertTrue($registry->isDegraded($uri));

        self::assertSame(
            SourceParseHealth::Healthy,
            $resolver->resolve($project, new Document($uri, 'php', 2, '<?php final class Article {}')),
        );
        self::assertFalse($registry->isDegraded($uri));
    }

    public function testHealthyStateClearsTheUriAfterProjectRemapping(): void
    {
        $registry = new SourceOverlayHealthRegistry();
        $uri = 'file:///workspace/src/Article.php';
        $registry->record(new Project('/workspace', 'file:///workspace'), $uri, SourceParseHealth::Partial);

        $registry->record(new Project('/workspace/src', 'file:///workspace/src'), $uri, SourceParseHealth::Healthy);

        self::assertFalse($registry->isDegraded($uri));
    }

    public function testProjectRemovalClearsDegradedDocuments(): void
    {
        $registry = new SourceOverlayHealthRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $uri = 'file:///workspace/src/Article.php';
        $registry->record($project, $uri, SourceParseHealth::Partial);

        $registry->removeProject($project);

        self::assertFalse($registry->isDegraded($uri));
    }

    public function testDoesNotParseNonPhpDocumentsAsDegradedPhp(): void
    {
        $registry = new SourceOverlayHealthRegistry();
        $resolver = new PhpParseHealthResolver(new TolerantPhpParser(new Parser()), $registry);
        $project = new Project('/workspace', 'file:///workspace');
        $document = new Document('file:///workspace/templates/page.html.twig', 'twig', 1, '{{ broken');

        self::assertSame(SourceParseHealth::Healthy, $resolver->resolve($project, $document));
        self::assertFalse($registry->isDegraded($document->uri));
    }
}
