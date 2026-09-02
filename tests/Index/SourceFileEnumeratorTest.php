<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Index\SourceFileEnumerator;
use Symfony\Lsp\Project\GitignoreMatcher;
use Symfony\Lsp\Project\GlobPatternCompiler;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;

final class SourceFileEnumeratorTest extends TestCase
{
    private string $directory;
    private Project $project;
    private ProjectFileScopeRegistry $fileScope;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/symfony-lsp-enumerator-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/src', 0777, true);
        $this->project = new Project($this->directory, 'file://'.$this->directory);
        $this->fileScope = new ProjectFileScopeRegistry(new GlobPatternCompiler());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->directory, $this->directory.'-outside']);
    }

    public function testEnumeratesFilesAndTraversalFailuresTogether(): void
    {
        if ('Windows' === \PHP_OS_FAMILY || (\function_exists('posix_geteuid') && 0 === posix_geteuid())) {
            self::markTestSkipped('Directory permissions are not enforced in this environment.');
        }
        file_put_contents($this->directory.'/src/Controller.php', '<?php');
        mkdir($this->directory.'/blocked');
        chmod($this->directory.'/blocked', 0000);

        try {
            $entries = iterator_to_array($this->enumerator()->entries($this->project));
        } finally {
            chmod($this->directory.'/blocked', 0700);
        }

        self::assertContains(['path' => Path::canonicalize($this->directory.'/src/Controller.php')], $entries);
        self::assertContains(['directory' => Path::canonicalize($this->directory.'/blocked'), 'error' => 'unreadable'], $entries);
    }

    public function testIgnoresExcludedSymlinkDirectoriesOutsideTheProject(): void
    {
        if ('Windows' === \PHP_OS_FAMILY || !\function_exists('symlink')) {
            self::markTestSkipped('Directory symlinks are not supported in this environment.');
        }
        $outside = $this->directory.'-outside';
        mkdir($outside);
        if (!symlink($outside, $this->directory.'/linked')) {
            self::markTestSkipped('Unable to create a directory symlink in this environment.');
        }
        $this->fileScope->configure($this->project, ['linked/**']);

        self::assertSame([], iterator_to_array($this->enumerator()->entries($this->project)));
        self::assertSame(
            [['directory' => Path::canonicalize($this->directory.'/linked'), 'error' => 'outside']],
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
            $directory = \dirname($this->directory.'/'.$file);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($this->directory.'/'.$file, '');
        }
        $this->fileScope->configure($this->project, [$pattern]);

        $included = [];
        foreach ($this->enumerator()->files($this->project) as $path) {
            $included[] = str_replace('\\', '/', Path::makeRelative($path, $this->directory));
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
        mkdir($this->directory.'/.git');
        file_put_contents($this->directory.'/.gitignore', "/ignored/\n/.env.local\n");
        mkdir($this->directory.'/ignored');
        file_put_contents($this->directory.'/ignored/Cache.php', '<?php');
        file_put_contents($this->directory.'/.env.local', "APP_ENV=test\n");
        file_put_contents($this->directory.'/src/Included.php', '<?php');
        file_put_contents($this->directory.'/src/Excluded.php', '<?php');
        $this->fileScope->configure($this->project, ['src/Excluded.php']);

        $default = array_values(iterator_to_array($this->enumerator()->files($this->project)));
        $withExcluded = array_values(iterator_to_array($this->enumerator()->files($this->project, true)));

        self::assertSame([
            Path::canonicalize($this->directory.'/.env.local'),
            Path::canonicalize($this->directory.'/src/Included.php'),
        ], $this->sorted($default));
        self::assertSame([
            Path::canonicalize($this->directory.'/.env.local'),
            Path::canonicalize($this->directory.'/src/Excluded.php'),
            Path::canonicalize($this->directory.'/src/Included.php'),
        ], $this->sorted($withExcluded));
    }

    private function enumerator(): SourceFileEnumerator
    {
        return new SourceFileEnumerator(new GitignoreMatcher(), $this->fileScope);
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
