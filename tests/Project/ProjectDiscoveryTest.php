<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
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
        (new Filesystem())->remove($this->temporaryDirectory);
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

    public function testDiscoversNestedAndExplicitProjectRoots(): void
    {
        mkdir($this->temporaryDirectory.'/.hidden', 0777, true);
        mkdir($this->temporaryDirectory.'/apps/admin', 0777, true);
        mkdir($this->temporaryDirectory.'/apps/ignored', 0777, true);
        mkdir($this->temporaryDirectory.'/vendor/package', 0777, true);
        foreach (['.hidden', 'apps/admin', 'apps/ignored', 'vendor/package'] as $path) {
            file_put_contents($this->temporaryDirectory.'/'.$path.'/composer.json', json_encode([
                'require' => ['symfony/framework-bundle' => '^8.0'],
            ], \JSON_THROW_ON_ERROR));
        }
        $discovery = new ProjectDiscovery(new UriToPathConverter());
        $workspace = [['uri' => 'file://'.$this->temporaryDirectory]];

        $projects = $discovery->discover($workspace);
        self::assertSame([
            $this->temporaryDirectory.'/.hidden',
            $this->temporaryDirectory.'/apps/admin',
            $this->temporaryDirectory.'/apps/ignored',
        ], array_map(static fn (Project $project): string => $project->rootPath(), $projects));

        $projects = $discovery->discover($workspace, ['apps/admin']);
        self::assertCount(1, $projects);
        self::assertSame($this->temporaryDirectory.'/apps/admin', $projects[0]->rootPath());
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
