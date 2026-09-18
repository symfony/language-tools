<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Tests\Support\ProjectPaths;

final class ProjectPathPolicyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/symfony-lsp-policy-'.bin2hex(random_bytes(6));
        foreach (['assets/vendor', 'node_modules', 'src/var', 'templates/vendor', 'var/cache', 'vendor/acme'] as $path) {
            mkdir($this->directory.'/'.$path, 0777, true);
        }
        file_put_contents($this->directory.'/.gitignore', "/var/cache/\n/vendor/\n/assets/vendor/\n*.log\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    #[DataProvider('paths')]
    public function testExcludesToolOwnedAndIgnoredPathsOnly(string $relativePath, bool $excluded): void
    {
        self::assertSame($excluded, ProjectPaths::policy()->isExcluded($this->project(), $this->directory.'/'.$relativePath));
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
        file_put_contents($this->directory.'/.gitignore', "/.env.local\n");

        self::assertFalse(ProjectPaths::policy()->isExcluded($this->project(), $this->directory.'/.env.local'));
    }

    public function testExcludesTheDeclaredComposerInstallationDirectory(): void
    {
        $project = new Project($this->directory, 'file://'.$this->directory, 'libraries');

        self::assertTrue(ProjectPaths::policy()->isToolOwned($project, $this->directory.'/libraries/acme/Thing.php'));
        self::assertFalse(ProjectPaths::policy()->isToolOwned($project, $this->directory.'/vendor/acme/Thing.php'));
    }

    public function testIgnoresPathsOutsideTheProject(): void
    {
        self::assertFalse(ProjectPaths::policy()->isExcluded($this->project(), \dirname($this->directory).'/elsewhere/vendor/Thing.php'));
    }

    private function project(): Project
    {
        return new Project($this->directory, 'file://'.$this->directory);
    }
}
