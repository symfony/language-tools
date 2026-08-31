<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Index\SourceFileEnumerator;
use Symfony\Lsp\Project\GitignoreMatcher;
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
        $this->project = new Project($this->directory, 'file://'.$this->directory, '^8.0');
        $this->fileScope = new ProjectFileScopeRegistry();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
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
