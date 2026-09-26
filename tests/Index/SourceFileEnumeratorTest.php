<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Index\SourceFileEnumerator;
use Symfony\Lsp\Project\GlobPatternCompiler;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;
use Symfony\Lsp\Tests\Support\ProjectPaths;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class SourceFileEnumeratorTest extends TestCase
{
    private TestWorkspace $workspace;
    private Project $project;
    private ProjectFileScopeRegistry $fileScope;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-enumerator-');
        $this->workspace->mkdir('project/src');
        $this->project = new Project($this->root(), 'file://'.$this->root());
        $this->fileScope = new ProjectFileScopeRegistry(new GlobPatternCompiler());
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    private function root(): string
    {
        return $this->workspace->path('project');
    }

    public function testEnumeratesFilesAndTraversalFailuresTogether(): void
    {
        if ('Windows' === \PHP_OS_FAMILY || (\function_exists('posix_geteuid') && 0 === posix_geteuid())) {
            self::markTestSkipped('Directory permissions are not enforced in this environment.');
        }
        $this->workspace->write('project/src/Controller.php', '<?php');
        $this->workspace->mkdir('project/blocked');
        chmod($this->workspace->path('project/blocked'), 0000);

        try {
            $entries = iterator_to_array($this->enumerator()->entries($this->project));
        } finally {
            chmod($this->workspace->path('project/blocked'), 0700);
        }

        self::assertContains(['path' => Path::canonicalize($this->workspace->path('project/src/Controller.php'))], $entries);
        self::assertContains(['directory' => Path::canonicalize($this->workspace->path('project/blocked')), 'error' => 'unreadable'], $entries);
    }

    public function testIgnoresExcludedSymlinkDirectoriesOutsideTheProject(): void
    {
        if ('Windows' === \PHP_OS_FAMILY || !\function_exists('symlink')) {
            self::markTestSkipped('Directory symlinks are not supported in this environment.');
        }
        $this->workspace->mkdir('outside');
        if (!symlink($this->workspace->path('outside'), $this->workspace->path('project/linked'))) {
            self::markTestSkipped('Unable to create a directory symlink in this environment.');
        }
        $this->fileScope->configure($this->project, ['linked/**']);

        self::assertSame([], iterator_to_array($this->enumerator()->entries($this->project)));
        self::assertSame(
            [['directory' => Path::canonicalize($this->workspace->path('project/linked')), 'error' => 'outside']],
            array_values(iterator_to_array($this->enumerator()->entries($this->project, true))),
        );
    }

    /** @param list<string> $excluded */
    #[DataProvider('recursiveExcludePatterns')]
    public function testMatchesRecursiveExcludePatternsEverywhere(string $pattern, array $excluded): void
    {
        $files = [
            'page.twig',
            'src/Controller.php',
            'src/Admin/Controller.php',
            'templates/page.twig',
            'templates/admin/page.twig',
        ];
        foreach ($files as $file) {
            $this->workspace->write('project/'.$file, '');
        }
        $this->fileScope->configure($this->project, [$pattern]);

        $included = [];
        foreach ($this->enumerator()->files($this->project) as $path) {
            $included[] = str_replace('\\', '/', Path::makeRelative($path, $this->root()));
        }
        $expected = array_values(array_diff($files, $excluded));

        self::assertSame($this->sorted($expected), $this->sorted($included));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function recursiveExcludePatterns(): iterable
    {
        yield 'leading double star crosses directories' => ['**.twig', [
            'page.twig',
            'templates/page.twig',
            'templates/admin/page.twig',
        ]];
        yield 'embedded double star crosses directories' => ['src/**.php', [
            'src/Controller.php',
            'src/Admin/Controller.php',
        ]];
        yield 'double star path segment matches zero or more directories' => ['templates/**/page.twig', [
            'templates/page.twig',
            'templates/admin/page.twig',
        ]];
    }

    public function testKeepsRootDotenvFilesWhileApplyingGitignoreAndFileScopeRules(): void
    {
        $this->workspace->mkdir('project/.git');
        $this->workspace->write('project/.gitignore', "/ignored/\n/.env.local\n");
        $this->workspace->mkdir('project/ignored');
        $this->workspace->write('project/ignored/Cache.php', '<?php');
        $this->workspace->write('project/.env.local', "APP_ENV=test\n");
        $this->workspace->write('project/src/Included.php', '<?php');
        $this->workspace->write('project/src/Excluded.php', '<?php');
        $this->fileScope->configure($this->project, ['src/Excluded.php']);

        $default = array_values(iterator_to_array($this->enumerator()->files($this->project)));
        $withExcluded = array_values(iterator_to_array($this->enumerator()->files($this->project, true)));

        self::assertSame([
            Path::canonicalize($this->workspace->path('project/.env.local')),
            Path::canonicalize($this->workspace->path('project/src/Included.php')),
        ], $this->sorted($default));
        self::assertSame([
            Path::canonicalize($this->workspace->path('project/.env.local')),
            Path::canonicalize($this->workspace->path('project/src/Excluded.php')),
            Path::canonicalize($this->workspace->path('project/src/Included.php')),
        ], $this->sorted($withExcluded));
    }

    public function testEnumeratesApplicationDirectoriesNamedLikeDependencyDirectories(): void
    {
        $files = [
            '.git/config.yaml',
            'assets/node_modules/pkg/package.json',
            'assets/vendor/installed.php',
            'node_modules/pkg/package.json',
            'src/var/Value.php',
            'templates/vendor/show.html.twig',
            'var/cache/app.php',
            'vendor/acme/src/Thing.php',
        ];
        foreach ($files as $file) {
            $this->workspace->write('project/'.$file, '');
        }
        $this->workspace->write('project/.gitignore', "/var/\n/assets/vendor/\n");

        self::assertSame(['src/var/Value.php', 'templates/vendor/show.html.twig'], $this->relativeFiles($this->project));
    }

    public function testEnumeratesTheDirectoryComposerInstallsIntoInsteadOfEveryVendorDirectory(): void
    {
        foreach (['libraries/acme/Thing.php', 'vendor/acme/Thing.php'] as $file) {
            $this->workspace->write('project/'.$file, '<?php');
        }
        $project = new Project($this->root(), 'file://'.$this->root(), 'libraries');

        self::assertSame(['vendor/acme/Thing.php'], $this->relativeFiles($project));
    }

    private function enumerator(): SourceFileEnumerator
    {
        return ProjectPaths::enumerator($this->fileScope);
    }

    /** @return list<string> */
    private function relativeFiles(Project $project): array
    {
        $files = [];
        foreach ($this->enumerator()->files($project) as $path) {
            $files[] = str_replace('\\', '/', Path::makeRelative($path, $this->root()));
        }

        return $this->sorted($files);
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function sorted(array $paths): array
    {
        sort($paths);

        return $paths;
    }
}
