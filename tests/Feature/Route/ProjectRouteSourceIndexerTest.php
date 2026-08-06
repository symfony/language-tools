<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\PhpRouteDeclarationExtractor;
use Symfony\Lsp\Feature\Route\ProjectRouteSourceIndexer;
use Symfony\Lsp\Feature\Route\RouteDeclarationIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteReferenceExtractor;
use Symfony\Lsp\Feature\Route\RouteReferenceIndexRegistry;
use Symfony\Lsp\Feature\Route\TwigRouteReferenceExtractor;
use Symfony\Lsp\Feature\Route\YamlRouteDeclarationExtractor;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Tests\Support\InMemorySourceIndexStore;
use Symfony\Lsp\Tests\Support\NullProgressReporter;

final class ProjectRouteSourceIndexerTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory.'/src', 0777, true);
        mkdir($this->temporaryDirectory.'/config/routes', 0777, true);
        mkdir($this->temporaryDirectory.'/templates', 0777, true);
        mkdir($this->temporaryDirectory.'/vendor', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->temporaryDirectory.'/src/Controller.php');
        @unlink($this->temporaryDirectory.'/config/routes/admin.yaml');
        @unlink($this->temporaryDirectory.'/templates/navigation.html.twig');
        @unlink($this->temporaryDirectory.'/vendor/Ignored.php');
        @rmdir($this->temporaryDirectory.'/src');
        @rmdir($this->temporaryDirectory.'/config/routes');
        @rmdir($this->temporaryDirectory.'/config');
        @rmdir($this->temporaryDirectory.'/templates');
        @rmdir($this->temporaryDirectory.'/vendor');
        @rmdir($this->temporaryDirectory);
    }

    public function testIndexesApplicationPhpAndExcludesVendor(): void
    {
        file_put_contents($this->temporaryDirectory.'/src/Controller.php', <<<'PHP'
            <?php
            use Symfony\Component\Routing\Attribute\Route;
            #[Route('/article', name: 'article_list')]
            final class Controller {}
            PHP);
        file_put_contents($this->temporaryDirectory.'/config/routes/admin.yaml', <<<'YAML'
            admin_dashboard:
                path: /admin
                controller: App\Controller\AdminController
            YAML);
        file_put_contents(
            $this->temporaryDirectory.'/templates/navigation.html.twig',
            "{{ path('article_list') }}",
        );
        file_put_contents($this->temporaryDirectory.'/vendor/Ignored.php', <<<'PHP'
            <?php
            use Symfony\Component\Routing\Attribute\Route;
            #[Route('/ignored', name: 'ignored_route')]
            final class Ignored {}
            PHP);
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project(
            $this->temporaryDirectory,
            'file://'.$this->temporaryDirectory,
            '^8.0',
        )]);
        $indexes = new RouteDeclarationIndexRegistry();
        $referenceIndexes = new RouteReferenceIndexRegistry();
        $positionConverter = new PositionConverter();
        $documents = new DocumentStore();
        $indexer = new ProjectRouteSourceIndexer(
            $indexes,
            $referenceIndexes,
            new PhpRouteDeclarationExtractor($positionConverter, new TolerantPhpParser(new Parser())),
            new YamlRouteDeclarationExtractor($positionConverter),
            new RouteReferenceExtractor($positionConverter),
            new TwigRouteReferenceExtractor($positionConverter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
            new ProjectPathResolver(new UriToPathConverter()),
        );
        $scanner = new ApplicationSourceScanner(
            $projects,
            $documents,
            new ProjectIndexStatusRegistry(),
            new NullProgressReporter(),
            new InMemorySourceIndexStore(),
            new SourceIndexPayloadCodec(),
            new UriToPathConverter(),
            [$indexer],
        );

        $scanner->indexAll();

        self::assertCount(1, $indexes->forProject($project)->find('article_list'));
        self::assertCount(1, $indexes->forProject($project)->find('admin_dashboard'));
        self::assertCount(1, $referenceIndexes->forProject($project)->find('article_list'));
        self::assertSame([], $indexes->forProject($project)->find('ignored_route'));

        $scanner->indexAll();
        self::assertCount(1, $indexes->forProject($project)->find('article_list'));
        self::assertCount(1, $indexes->forProject($project)->find('admin_dashboard'));

        $uri = 'file://'.$this->temporaryDirectory.'/src/Controller.php';
        $documents->open(new Document($uri, 'php', 2, <<<'PHP'
            <?php
            use Symfony\Component\Routing\Attribute\Route;
            #[Route('/article', name: 'article_new')]
            final class Controller {}
            PHP));
        $scanner->updateOpenDocument(['textDocument' => ['uri' => $uri]]);

        self::assertSame([], $indexes->forProject($project)->find('article_list'));
        self::assertCount(1, $indexes->forProject($project)->find('article_new'));

        $documents->close($uri);
        $scanner->restoreClosedDocument(['textDocument' => ['uri' => $uri]]);

        self::assertCount(1, $indexes->forProject($project)->find('article_list'));
        self::assertSame([], $indexes->forProject($project)->find('article_new'));

        $packageUri = 'file://'.$this->temporaryDirectory.'/config/packages/framework.yaml';
        $documents->open(new Document($packageUri, 'yaml', 1, <<<'YAML'
            fake_route:
                path: /not-a-route
            YAML));
        $scanner->updateOpenDocument(['textDocument' => ['uri' => $packageUri]]);

        self::assertSame([], $indexes->forProject($project)->find('fake_route'));
    }
}
