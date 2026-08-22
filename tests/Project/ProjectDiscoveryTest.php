<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Project\GitignoreMatcher;
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
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^7.4'],
        ], \JSON_THROW_ON_ERROR));
        $uri = 'file://'.$this->temporaryDirectory;

        $projects = (new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher()))->discover([
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
                'type' => 'project',
                'require' => ['symfony/framework-bundle' => '^8.0'],
            ], \JSON_THROW_ON_ERROR));
        }
        $discovery = new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher());
        $workspace = [['uri' => 'file://'.$this->temporaryDirectory]];

        $projects = $discovery->discover($workspace);
        self::assertSame([
            $this->temporaryDirectory.'/.hidden',
            $this->temporaryDirectory.'/apps/admin',
            $this->temporaryDirectory.'/apps/ignored',
        ], array_map(static fn (Project $project): string => $project->rootPath(), $projects));

        file_put_contents($this->temporaryDirectory.'/apps/admin/composer.json', json_encode([
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
        $projects = $discovery->discover($workspace, ['apps/admin']);
        self::assertCount(1, $projects);
        self::assertSame($this->temporaryDirectory.'/apps/admin', $projects[0]->rootPath());
    }

    public function testSkipsGitignoredProjectsUnlessExplicitlyConfigured(): void
    {
        mkdir($this->temporaryDirectory.'/.git');
        mkdir($this->temporaryDirectory.'/ignored/app', 0777, true);
        mkdir($this->temporaryDirectory.'/apps/admin', 0777, true);
        file_put_contents($this->temporaryDirectory.'/.gitignore', "/ignored/\n");
        foreach (['ignored/app', 'apps/admin'] as $path) {
            file_put_contents($this->temporaryDirectory.'/'.$path.'/composer.json', json_encode([
                'type' => 'project',
                'require' => ['symfony/framework-bundle' => '^8.0'],
            ], \JSON_THROW_ON_ERROR));
        }
        $discovery = new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher());
        $workspace = [['uri' => 'file://'.$this->temporaryDirectory]];

        $projects = $discovery->discover($workspace);
        self::assertSame(
            [$this->temporaryDirectory.'/apps/admin'],
            array_map(static fn (Project $project): string => $project->rootPath(), $projects),
        );

        $projects = $discovery->discover($workspace, ['ignored/app']);
        self::assertCount(1, $projects);
        self::assertSame($this->temporaryDirectory.'/ignored/app', $projects[0]->rootPath());
    }

    public function testDiscoversProjectsAroundUnreadableDirectories(): void
    {
        if ('Windows' === \PHP_OS_FAMILY || (\function_exists('posix_geteuid') && 0 === posix_geteuid())) {
            self::markTestSkipped('Directory permissions are not enforced in this environment.');
        }

        file_put_contents($this->temporaryDirectory.'/composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
        mkdir($this->temporaryDirectory.'/volumes/mysql', 0777, true);
        chmod($this->temporaryDirectory.'/volumes/mysql', 0000);

        try {
            $projects = (new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher()))->discover([
                ['uri' => 'file://'.$this->temporaryDirectory],
            ]);
        } finally {
            chmod($this->temporaryDirectory.'/volumes/mysql', 0755);
        }

        self::assertCount(1, $projects);
        self::assertSame($this->temporaryDirectory, $projects[0]->rootPath());
    }

    public function testDiscoversLegacyApplicationsWithAConsoleMarker(): void
    {
        mkdir($this->temporaryDirectory.'/bin');
        file_put_contents($this->temporaryDirectory.'/bin/console', '');
        file_put_contents($this->temporaryDirectory.'/composer.json', json_encode([
            'require' => ['symfony/framework-bundle' => '^7.4'],
        ], \JSON_THROW_ON_ERROR));

        $projects = (new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher()))->discover([
            ['uri' => 'file://'.$this->temporaryDirectory],
        ]);

        self::assertCount(1, $projects);
        self::assertSame($this->temporaryDirectory, $projects[0]->rootPath());
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

    public function testDiscoversDistributionApplicationsWithATransitiveFrameworkBundle(): void
    {
        file_put_contents($this->temporaryDirectory.'/composer.json', json_encode([
            'type' => 'project',
            'require' => ['contao/manager-bundle' => '5.3.*'],
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($this->temporaryDirectory.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'contao/manager-bundle', 'version' => '5.3.49'],
                ['name' => 'symfony/framework-bundle', 'version' => 'v6.4.43'],
            ],
        ], \JSON_THROW_ON_ERROR));
        $uri = 'file://'.$this->temporaryDirectory;

        $projects = (new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher()))->discover([
            ['uri' => $uri, 'name' => 'application'],
        ]);

        self::assertCount(1, $projects);
        self::assertSame('6.4.43', $projects[0]->frameworkBundleConstraint());
    }

    public function testIgnoresApplicationsWithoutTheFrameworkBundleInTheLock(): void
    {
        file_put_contents($this->temporaryDirectory.'/composer.json', json_encode([
            'type' => 'project',
            'require' => ['laravel/framework' => '^12.0'],
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($this->temporaryDirectory.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'laravel/framework', 'version' => 'v12.1.0'],
                ['name' => 'symfony/console', 'version' => 'v7.4.1'],
            ],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame([], (new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher()))->discover([
            ['uri' => 'file://'.$this->temporaryDirectory],
        ]));
    }

    public function testIgnoresNonFrameworkProjectsAndInvalidComposerFiles(): void
    {
        file_put_contents($this->temporaryDirectory.'/composer.json', '{');
        $discovery = new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher());

        self::assertSame([], $discovery->discover([
            ['uri' => 'file://'.$this->temporaryDirectory],
        ]));

        file_put_contents($this->temporaryDirectory.'/composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/console' => '^7.4'],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame([], $discovery->discover([
            ['uri' => 'file://'.$this->temporaryDirectory],
        ]));
    }

    public function testIgnoresComposerPackages(): void
    {
        $discovery = new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher());
        file_put_contents($this->temporaryDirectory.'/composer.json', json_encode([
            'name' => 'symfony/example-bundle',
            'type' => 'symfony-bundle',
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame([], $discovery->discover([
            ['uri' => 'file://'.$this->temporaryDirectory],
        ]));

        file_put_contents($this->temporaryDirectory.'/composer.json', json_encode([
            'type' => 'project',
            'require-dev' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame([], $discovery->discover([
            ['uri' => 'file://'.$this->temporaryDirectory],
        ]));
    }
}
