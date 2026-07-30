<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\PhpRouteDeclarationExtractor;
use Symfony\Lsp\Feature\Route\ProjectRouteSourceIndexer;
use Symfony\Lsp\Feature\Route\RouteDeclarationIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteReferenceExtractor;
use Symfony\Lsp\Feature\Route\RouteReferenceIndexRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class ProjectRouteSourceIndexerTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory.'/src', 0777, true);
        mkdir($this->temporaryDirectory.'/vendor', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->temporaryDirectory.'/src/Controller.php');
        @unlink($this->temporaryDirectory.'/vendor/Ignored.php');
        @rmdir($this->temporaryDirectory.'/src');
        @rmdir($this->temporaryDirectory.'/vendor');
        @rmdir($this->temporaryDirectory);
    }

    public function testIndexesApplicationPhpAndExcludesVendor(): void
    {
        file_put_contents($this->temporaryDirectory.'/src/Controller.php', <<<'PHP'
            <?php
            #[Route('/article', name: 'article_list')]
            final class Controller {}
            PHP);
        file_put_contents($this->temporaryDirectory.'/vendor/Ignored.php', <<<'PHP'
            <?php
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
        $indexer = new ProjectRouteSourceIndexer(
            $projects,
            $indexes,
            $referenceIndexes,
            new PhpRouteDeclarationExtractor($positionConverter),
            new RouteReferenceExtractor($positionConverter),
        );

        $indexer->indexAll();

        self::assertCount(1, $indexes->forProject($project)->find('article_list'));
        self::assertSame([], $indexes->forProject($project)->find('ignored_route'));
    }
}
