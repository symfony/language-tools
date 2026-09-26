<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Tests\Support\ProjectPaths;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class ProjectPathPolicyTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-policy-');
        foreach (['assets/vendor', 'node_modules', 'src/var', 'templates/vendor', 'var/cache', 'vendor/acme'] as $path) {
            $this->workspace->mkdir($path);
        }
        $this->workspace->write('.gitignore', "/var/cache/\n/vendor/\n/assets/vendor/\n*.log\n");
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    #[DataProvider('paths')]
    public function testExcludesToolOwnedAndIgnoredPathsOnly(string $relativePath, bool $excluded): void
    {
        self::assertSame($excluded, ProjectPaths::policy()->isExcluded($this->project(), $this->workspace->path($relativePath)));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function paths(): iterable
    {
        yield 'application template directory named vendor' => ['templates/vendor/show.html.twig', false];
        yield 'application directory named var' => ['src/var/Value.php', false];
        yield 'composer installation directory' => ['vendor/acme/Thing.php', true];
        yield 'node dependency directory' => ['node_modules/pkg/index.js', true];
        yield 'git directory' => ['.git/config', true];
        yield 'kernel cache directory the project ignores' => ['var/cache/app.php', true];
        yield 'asset vendor directory the project ignores' => ['assets/vendor/installed.php', true];
        yield 'ignored file' => ['src/debug.log', true];
        yield 'language tools storage directory' => ['var/symfony-lsp/1.0.0/index/source.jsonl', true];
        yield 'application source' => ['src/Controller.php', false];
    }

    public function testKeepsProjectRootDotenvFilesTheProjectIgnores(): void
    {
        $this->workspace->write('.gitignore', "/.env.local\n");

        self::assertFalse(ProjectPaths::policy()->isExcluded($this->project(), $this->workspace->path('.env.local')));
    }

    public function testExcludesTheDeclaredComposerInstallationDirectory(): void
    {
        $project = new Project($this->workspace->rootPath, 'file://'.$this->workspace->rootPath, 'libraries');

        self::assertTrue(ProjectPaths::policy()->isToolOwned($project, $this->workspace->path('libraries/acme/Thing.php')));
        self::assertFalse(ProjectPaths::policy()->isToolOwned($project, $this->workspace->path('vendor/acme/Thing.php')));
    }

    public function testIgnoresPathsOutsideTheProject(): void
    {
        self::assertFalse(ProjectPaths::policy()->isExcluded($this->project(), \dirname($this->workspace->rootPath).'/elsewhere/vendor/Thing.php'));
    }

    private function project(): Project
    {
        return new Project($this->workspace->rootPath, 'file://'.$this->workspace->rootPath);
    }
}
