<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectDiscovery;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

final class ProjectDiscoveryTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        @unlink($this->temporaryDirectory.'/composer.json');
        @rmdir($this->temporaryDirectory);
    }

    public function testDiscoversFrameworkBundleProjects(): void
    {
        file_put_contents($this->temporaryDirectory.'/composer.json', json_encode([
            'require' => ['symfony/framework-bundle' => '^7.4'],
        ], \JSON_THROW_ON_ERROR));
        $uri = 'file://'.$this->temporaryDirectory;

        $projects = (new ProjectDiscovery(new UriToPathConverter()))->discover([
            ['uri' => $uri, 'name' => 'application'],
        ]);

        self::assertCount(1, $projects);
        self::assertSame($this->temporaryDirectory, $projects[0]->rootPath());
        self::assertSame($uri, $projects[0]->rootUri());
        self::assertSame('^7.4', $projects[0]->frameworkBundleConstraint());
    }

    public function testSelectsMostSpecificProjectForDocument(): void
    {
        $parent = new Project('/workspace', 'file:///workspace', '^8.0');
        $child = new Project('/workspace/app', 'file:///workspace/app', '^8.0');
        $registry = new ProjectRegistry();
        $registry->replace([$parent, $child]);

        self::assertSame($child, $registry->forDocumentUri('file:///workspace/app/src/Controller.php'));
        self::assertSame($parent, $registry->forDocumentUri('file:///workspace/src/Service.php'));
        self::assertNull($registry->forDocumentUri('file:///other/src/Service.php'));
    }

    public function testIgnoresNonFrameworkProjectsAndInvalidComposerFiles(): void
    {
        file_put_contents($this->temporaryDirectory.'/composer.json', '{');
        $discovery = new ProjectDiscovery(new UriToPathConverter());

        self::assertSame([], $discovery->discover([
            ['uri' => 'file://'.$this->temporaryDirectory],
        ]));

        file_put_contents($this->temporaryDirectory.'/composer.json', json_encode([
            'require' => ['symfony/console' => '^7.4'],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame([], $discovery->discover([
            ['uri' => 'file://'.$this->temporaryDirectory],
        ]));
    }
}
