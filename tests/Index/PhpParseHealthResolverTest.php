<?php

namespace Symfony\Lsp\Tests\Index;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\PhpParseHealthResolver;
use Symfony\Lsp\Index\SourceParseHealth;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;

final class PhpParseHealthResolverTest extends TestCase
{
    #[DataProvider('healthyPhpProvider')]
    public function testRecognizesHealthyModernPhp(string $source): void
    {
        $resolver = new PhpParseHealthResolver(new TolerantPhpParser(new Parser()));

        self::assertSame(SourceParseHealth::Healthy, $resolver->resolve(new Document('file:///workspace/src/Article.php', 'php', 1, $source)));
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

    public function testMarksInvalidPhpAsPartial(): void
    {
        $resolver = new PhpParseHealthResolver(new TolerantPhpParser(new Parser()));

        self::assertSame(
            SourceParseHealth::Partial,
            $resolver->resolve(new Document('file:///workspace/src/Article.php', 'php', 1, '<?php final class Article { public function title(')),
        );
    }

    public function testDoesNotParseNonPhpDocuments(): void
    {
        $resolver = new PhpParseHealthResolver(new TolerantPhpParser(new Parser()));

        self::assertSame(
            SourceParseHealth::Healthy,
            $resolver->resolve(new Document('file:///workspace/templates/page.html.twig', 'twig', 1, '{{ broken')),
        );
    }
}
