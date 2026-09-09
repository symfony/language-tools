<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Project\GitignoreMatcher;

final class GitignoreMatcherTest extends TestCase
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

    public function testMatchesRootGitignorePatterns(): void
    {
        file_put_contents($this->temporaryDirectory.'/.gitignore', "*.log\ntmp/\n");
        mkdir($this->temporaryDirectory.'/tmp/phpstan', 0777, true);
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/debug.log'));
        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/tmp/phpstan/cache.php'));
        self::assertFalse($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/src/Controller.php'));
    }

    public function testMatchesGitignoreFilesAboveTheProjectRoot(): void
    {
        mkdir($this->temporaryDirectory.'/.git');
        mkdir($this->temporaryDirectory.'/app/tmp', 0777, true);
        file_put_contents($this->temporaryDirectory.'/.gitignore', "app/tmp/\n");
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->temporaryDirectory.'/app', $this->temporaryDirectory.'/app/tmp/cache.php'));
        self::assertFalse($matcher->isIgnored($this->temporaryDirectory.'/app', $this->temporaryDirectory.'/app/src/Controller.php'));
    }

    public function testRefreshesResultsWhenAGitignoreFileChanges(): void
    {
        mkdir($this->temporaryDirectory.'/tmp');
        file_put_contents($this->temporaryDirectory.'/.gitignore', "tmp/\n");
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/tmp/cache.php'));

        file_put_contents($this->temporaryDirectory.'/.gitignore', "*.log\n");

        self::assertFalse($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/tmp/cache.php'));
        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/tmp/app.log'));
    }

    public function testReincludesEverythingBelowADirectoryRestoredByANegatedPattern(): void
    {
        file_put_contents($this->temporaryDirectory.'/.gitignore', "custom/*\n!custom/static-plugins/\n");
        mkdir($this->temporaryDirectory.'/custom/static-plugins/Demo/src', 0777, true);
        mkdir($this->temporaryDirectory.'/custom/plugins', 0777, true);
        $matcher = new GitignoreMatcher();

        self::assertFalse($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/custom/static-plugins/Demo/composer.json'));
        self::assertFalse($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/custom/static-plugins/Demo/src/Theme.php'));
        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/custom/plugins/Legacy.php'));
    }

    public function testKeepsFilesIgnoredWhenTheirParentDirectoryItselfIsExcluded(): void
    {
        file_put_contents($this->temporaryDirectory.'/.gitignore', "custom/\n!custom/static-plugins/\n");
        mkdir($this->temporaryDirectory.'/custom/static-plugins/Demo', 0777, true);
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/custom/static-plugins/Demo/composer.json'));
    }

    public function testFilterKeepsFilesRestoredByANegatedPattern(): void
    {
        file_put_contents($this->temporaryDirectory.'/.gitignore', "custom/*\n!custom/static-plugins/\n");
        mkdir($this->temporaryDirectory.'/custom/static-plugins/Demo', 0777, true);
        mkdir($this->temporaryDirectory.'/custom/plugins', 0777, true);
        $matcher = new GitignoreMatcher();

        $files = [
            $this->temporaryDirectory.'/custom/static-plugins/Demo/composer.json',
            $this->temporaryDirectory.'/custom/plugins/composer.json',
        ];

        self::assertSame(
            [$this->temporaryDirectory.'/custom/static-plugins/Demo/composer.json'],
            iterator_to_array($matcher->filter($files, $this->temporaryDirectory), false),
        );
    }

    public function testAppliesTheLastMatchingPattern(): void
    {
        file_put_contents($this->temporaryDirectory.'/.gitignore', "*.log\n!important.log\n*.tmp.log\n");
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/debug.log'));
        self::assertFalse($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/important.log'));
        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/build.tmp.log'));
    }

    public function testNestedGitignoreFilesOverrideTheRootOnes(): void
    {
        file_put_contents($this->temporaryDirectory.'/.gitignore', "*.php\n");
        mkdir($this->temporaryDirectory.'/src');
        file_put_contents($this->temporaryDirectory.'/src/.gitignore', "!Kernel.php\n");
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/Foo.php'));
        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/src/Foo.php'));
        self::assertFalse($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/src/Kernel.php'));
    }

    public function testAnchorsPatternsContainingASlash(): void
    {
        file_put_contents($this->temporaryDirectory.'/.gitignore', "/build\nsrc/cache\ncache\n");
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/build/app.php'));
        self::assertFalse($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/src/build/app.php'));
        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/src/cache/app.php'));
        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/var/deep/cache/app.php'));
    }

    public function testOnlyMatchesDirectoriesForPatternsEndingWithASlash(): void
    {
        file_put_contents($this->temporaryDirectory.'/.gitignore', "cache/\n");
        mkdir($this->temporaryDirectory.'/var/cache', 0777, true);
        touch($this->temporaryDirectory.'/cache');
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/var/cache/app.php'));
        self::assertFalse($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/cache'));
    }

    public function testSupportsWildcardsAndCharacterClasses(): void
    {
        file_put_contents($this->temporaryDirectory.'/.gitignore', "assets/**\n**/generated\nconfig[0-9].yaml\nlog?.txt\n");
        mkdir($this->temporaryDirectory.'/assets/build', 0777, true);
        mkdir($this->temporaryDirectory.'/src/deep/generated', 0777, true);
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/assets/build/app.js'));
        self::assertFalse($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/assets'));
        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/src/deep/generated/Dto.php'));
        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/config1.yaml'));
        self::assertFalse($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/config12.yaml'));
        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/loga.txt'));
    }

    public function testSkipsCommentsAndHonorsEscapedPrefixes(): void
    {
        file_put_contents($this->temporaryDirectory.'/.gitignore', "# a comment\n\\#hash.txt\n\\!bang.txt\n");
        $matcher = new GitignoreMatcher();

        self::assertFalse($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/a.txt'));
        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/#hash.txt'));
        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/!bang.txt'));
    }

    public function testIgnoresUnescapedTrailingWhitespace(): void
    {
        file_put_contents($this->temporaryDirectory.'/.gitignore', "trailing.txt   \nescaped.txt\\ \n");
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/trailing.txt'));
        self::assertFalse($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/escaped.txt'));
        self::assertTrue($matcher->isIgnored($this->temporaryDirectory, $this->temporaryDirectory.'/escaped.txt '));
    }
}
