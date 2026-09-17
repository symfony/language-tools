<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Feature\Twig\TemplateDeclaration;
use Symfony\Lsp\Feature\Twig\TemplateIndex;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Tests\Support\TestWorkspace;
use Twig\Loader\FilesystemLoader;

/*
 * Twig's filesystem loader is the reference implementation for template name
 * spellings: every accepted spelling must resolve to the same file here.
 */
final class TemplateIndexTwigConformanceTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace();
        $this->workspace->write('templates/shop/probe.html.twig', '<p>ok</p>');
        $this->workspace->write('admin-templates/foo.html.twig', '<p>admin</p>');
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    /** @return iterable<string, array{string}> */
    public static function names(): iterable
    {
        yield 'plain' => ['shop/probe.html.twig'];
        yield 'leading dot slash' => ['./shop/probe.html.twig'];
        yield 'leading slash' => ['/shop/probe.html.twig'];
        yield 'leading double slash' => ['//shop/probe.html.twig'];
        yield 'leading slash dot slash' => ['/./shop/probe.html.twig'];
        yield 'leading dot double slash' => ['.//shop/probe.html.twig'];
        yield 'inner double slash' => ['shop//probe.html.twig'];
        yield 'backslash separator' => ['shop\\probe.html.twig'];
        yield 'parent segment' => ['/shop/../shop/probe.html.twig'];
        yield 'escaping parent segment' => ['../shop/probe.html.twig'];
        yield 'namespaced' => ['@Admin/foo.html.twig'];
        yield 'namespaced inner double slash' => ['@Admin//foo.html.twig'];
        yield 'namespaced dot segment' => ['@Admin/./foo.html.twig'];
        yield 'leading slash before namespace' => ['/@Admin/foo.html.twig'];
        yield 'leading dot slash before namespace' => ['./@Admin/foo.html.twig'];
        yield 'unknown' => ['shop/missing.html.twig'];
    }

    #[DataProvider('names')]
    public function testResolvesTemplateNamesLikeTheTwigFilesystemLoader(string $name): void
    {
        $loader = new FilesystemLoader([$this->workspace->path('templates')]);
        $loader->addPath($this->workspace->path('admin-templates'), 'Admin');
        $expected = $loader->exists($name) ? $loader->getSourceContext($name)->getPath() : null;

        $declaration = $this->index()->get($name);

        if (null === $expected) {
            self::assertNull($declaration);

            return;
        }
        self::assertNotNull($declaration);
        self::assertSame(realpath($expected), realpath((new UriToPathConverter())->convert($declaration->uri) ?? ''));
    }

    #[DataProvider('names')]
    public function testCompletesOnlyPrefixesOfNamesThatResolve(string $name): void
    {
        $index = $this->index();

        $matches = array_column($index->matching(substr($name, 0, -3)), 'name');

        if (null === $declaration = $index->get($name)) {
            self::assertSame([], $matches);

            return;
        }
        self::assertContains($declaration->name, $matches);
    }

    private function index(): TemplateIndex
    {
        $converter = new UriToPathConverter();
        $range = new Range(new Position(0, 0), new Position(0, 0));
        $index = new TemplateIndex(new DependencyInjectionSourceIndex());
        $index->replaceRuntime(
            true,
            new TemplateDeclaration(
                'shop/probe.html.twig',
                $converter->toUri($this->workspace->path('templates/shop/probe.html.twig')),
                $range,
            ),
            new TemplateDeclaration(
                '@Admin/foo.html.twig',
                $converter->toUri($this->workspace->path('admin-templates/foo.html.twig')),
                $range,
            ),
        );

        return $index;
    }
}
