<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\GitignoreMatcher;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectDiscovery;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class ProjectDiscoveryTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-');
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testDiscoversFrameworkBundleProjects(): void
    {
        $this->workspace->write('composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^7.4'],
        ], \JSON_THROW_ON_ERROR));
        $uri = 'file://'.$this->workspace->rootPath;

        $projects = (new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher()))->discover([
            ['uri' => $uri, 'name' => 'application'],
        ]);

        self::assertCount(1, $projects);
        self::assertSame($this->workspace->rootPath, $projects[0]->rootPath);
        self::assertSame($uri, $projects[0]->rootUri);
    }

    public function testDiscoversNestedAndExplicitProjectRoots(): void
    {
        $this->workspace->mkdir('.hidden');
        $this->workspace->mkdir('apps/admin');
        $this->workspace->mkdir('apps/ignored');
        $this->workspace->write('.gitignore', "/vendor/\n");
        $this->workspace->mkdir('vendor/package');
        foreach (['.hidden', 'apps/admin', 'apps/ignored', 'vendor/package'] as $path) {
            $this->workspace->write($path.'/composer.json', json_encode([
                'type' => 'project',
                'require' => ['symfony/framework-bundle' => '^8.0'],
            ], \JSON_THROW_ON_ERROR));
        }
        $discovery = new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher());
        $workspace = [['uri' => 'file://'.$this->workspace->rootPath]];

        $projects = $discovery->discover($workspace);
        self::assertSame([
            $this->workspace->path('.hidden'),
            $this->workspace->path('apps/admin'),
            $this->workspace->path('apps/ignored'),
        ], array_map(static fn (Project $project): string => $project->rootPath, $projects));

        $this->workspace->write('apps/admin/composer.json', json_encode([
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
        $projects = $discovery->discover($workspace, [$this->workspace->path('apps/admin')]);
        self::assertCount(1, $projects);
        self::assertSame($this->workspace->path('apps/admin'), $projects[0]->rootPath);
    }

    public function testSkipsProjectsInstalledInTheDeclaredComposerVendorDirectory(): void
    {
        $this->workspace->write('composer.json', json_encode([
            'type' => 'project',
            'config' => ['vendor-dir' => 'libraries'],
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
        $this->workspace->mkdir('libraries/package');
        $this->workspace->write('libraries/package/composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));

        $projects = (new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher()))->discover([
            ['uri' => 'file://'.$this->workspace->rootPath],
        ]);

        self::assertSame(
            [$this->workspace->rootPath],
            array_map(static fn (Project $project): string => $project->rootPath, $projects),
        );
        self::assertSame('libraries', $projects[0]->vendorPath);
    }

    public function testSkipsGitignoredProjectsUnlessExplicitlyConfigured(): void
    {
        $this->workspace->mkdir('.git');
        $this->workspace->mkdir('ignored/app');
        $this->workspace->mkdir('apps/admin');
        $this->workspace->write('.gitignore', "/ignored/\n");
        foreach (['ignored/app', 'apps/admin'] as $path) {
            $this->workspace->write($path.'/composer.json', json_encode([
                'type' => 'project',
                'require' => ['symfony/framework-bundle' => '^8.0'],
            ], \JSON_THROW_ON_ERROR));
        }
        $discovery = new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher());
        $workspace = [['uri' => 'file://'.$this->workspace->rootPath]];

        $projects = $discovery->discover($workspace);
        self::assertSame(
            [$this->workspace->path('apps/admin')],
            array_map(static fn (Project $project): string => $project->rootPath, $projects),
        );

        $projects = $discovery->discover($workspace, [$this->workspace->path('ignored/app')]);
        self::assertCount(1, $projects);
        self::assertSame($this->workspace->path('ignored/app'), $projects[0]->rootPath);
    }

    public function testDiscoversProjectsAroundUnreadableDirectories(): void
    {
        if ('Windows' === \PHP_OS_FAMILY || (\function_exists('posix_geteuid') && 0 === posix_geteuid())) {
            self::markTestSkipped('Directory permissions are not enforced in this environment.');
        }

        $this->workspace->write('composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));
        $this->workspace->mkdir('volumes/mysql');
        chmod($this->workspace->path('volumes/mysql'), 0000);

        try {
            $projects = (new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher()))->discover([
                ['uri' => 'file://'.$this->workspace->rootPath],
            ]);
        } finally {
            chmod($this->workspace->path('volumes/mysql'), 0755);
        }

        self::assertCount(1, $projects);
        self::assertSame($this->workspace->rootPath, $projects[0]->rootPath);
    }

    public function testDiscoversLegacyApplicationsWithAConsoleMarker(): void
    {
        $this->workspace->mkdir('bin');
        $this->workspace->write('bin/console', '');
        $this->workspace->write('composer.json', json_encode([
            'require' => ['symfony/framework-bundle' => '^7.4'],
        ], \JSON_THROW_ON_ERROR));

        $projects = (new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher()))->discover([
            ['uri' => 'file://'.$this->workspace->rootPath],
        ]);

        self::assertCount(1, $projects);
        self::assertSame($this->workspace->rootPath, $projects[0]->rootPath);
    }

    public function testSelectsMostSpecificProjectForDocument(): void
    {
        $parent = new Project('/workspace', 'file:///workspace');
        $child = new Project('/workspace/app', 'file:///workspace/app');
        $registry = new ProjectRegistry();
        $registry->replace([$parent, $child]);

        self::assertSame($child, $registry->forDocumentUri('file:///workspace/app/src/Controller.php'));
        self::assertSame($parent, $registry->forDocumentUri('file:///workspace/src/Service.php'));
        self::assertNull($registry->forDocumentUri('file:///other/src/Service.php'));
    }

    public function testDiscoversDistributionApplicationsWithATransitiveFrameworkBundle(): void
    {
        $this->workspace->write('composer.json', json_encode([
            'type' => 'project',
            'require' => ['contao/manager-bundle' => '5.3.*'],
        ], \JSON_THROW_ON_ERROR));
        $this->workspace->write('composer.lock', json_encode([
            'packages' => [
                ['name' => 'contao/manager-bundle', 'version' => '5.3.49'],
                ['name' => 'symfony/framework-bundle', 'version' => 'v6.4.43'],
            ],
        ], \JSON_THROW_ON_ERROR));
        $uri = 'file://'.$this->workspace->rootPath;

        $projects = (new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher()))->discover([
            ['uri' => $uri, 'name' => 'application'],
        ]);

        self::assertCount(1, $projects);
    }

    public function testIgnoresApplicationsWithoutTheFrameworkBundleInTheLock(): void
    {
        $this->workspace->write('composer.json', json_encode([
            'type' => 'project',
            'require' => ['laravel/framework' => '^12.0'],
        ], \JSON_THROW_ON_ERROR));
        $this->workspace->write('composer.lock', json_encode([
            'packages' => [
                ['name' => 'laravel/framework', 'version' => 'v12.1.0'],
                ['name' => 'symfony/console', 'version' => 'v7.4.1'],
            ],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame([], (new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher()))->discover([
            ['uri' => 'file://'.$this->workspace->rootPath],
        ]));
    }

    public function testIgnoresNonFrameworkProjectsAndInvalidComposerFiles(): void
    {
        $this->workspace->write('composer.json', '{');
        $discovery = new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher());

        self::assertSame([], $discovery->discover([
            ['uri' => 'file://'.$this->workspace->rootPath],
        ]));

        $this->workspace->write('composer.json', json_encode([
            'type' => 'project',
            'require' => ['symfony/console' => '^7.4'],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame([], $discovery->discover([
            ['uri' => 'file://'.$this->workspace->rootPath],
        ]));
    }

    public function testIgnoresComposerPackages(): void
    {
        $discovery = new ProjectDiscovery(new UriToPathConverter(), new GitignoreMatcher());
        $this->workspace->write('composer.json', json_encode([
            'name' => 'symfony/example-bundle',
            'type' => 'symfony-bundle',
            'require' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame([], $discovery->discover([
            ['uri' => 'file://'.$this->workspace->rootPath],
        ]));

        $this->workspace->write('composer.json', json_encode([
            'type' => 'project',
            'require-dev' => ['symfony/framework-bundle' => '^8.0'],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame([], $discovery->discover([
            ['uri' => 'file://'.$this->workspace->rootPath],
        ]));
    }
}
