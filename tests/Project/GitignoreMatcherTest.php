<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\GitignoreMatcher;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class GitignoreMatcherTest extends TestCase
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

    public function testMatchesRootGitignorePatterns(): void
    {
        $this->workspace->write('.gitignore', "*.log\ntmp/\n");
        $this->workspace->mkdir('tmp/phpstan');
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('debug.log')));
        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('tmp/phpstan/cache.php')));
        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('src/Controller.php')));
    }

    public function testMatchesPathsBelowASymlinkedRoot(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('Directory symlinks are not supported in this environment.');
        }
        $this->workspace->mkdir('project/var');
        $this->workspace->write('project/.gitignore', "var/\n");
        if (!symlink($this->workspace->path('project'), $this->workspace->path('link'))) {
            self::markTestSkipped('Unable to create a directory symlink in this environment.');
        }
        $root = $this->workspace->path('link');
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($root, $root.'/var/cache.php'));
        self::assertFalse($matcher->isIgnored($root, $root.'/src/Controller.php'));
    }

    public function testMatchesGitignoreFilesAboveTheProjectRoot(): void
    {
        $this->workspace->mkdir('.git');
        $this->workspace->mkdir('app/tmp');
        $this->workspace->write('.gitignore', "app/tmp/\n");
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->workspace->path('app'), $this->workspace->path('app/tmp/cache.php')));
        self::assertFalse($matcher->isIgnored($this->workspace->path('app'), $this->workspace->path('app/src/Controller.php')));
    }

    public function testRefreshesResultsWhenAGitignoreFileChanges(): void
    {
        $this->workspace->mkdir('tmp');
        $this->workspace->write('.gitignore', "tmp/\n");
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('tmp/cache.php')));

        $this->workspace->write('.gitignore', "*.log\n");

        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('tmp/cache.php')));
        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('tmp/app.log')));
    }

    public function testRefreshesResultsWhenANestedGitignoreFileChanges(): void
    {
        $this->workspace->write('.gitignore', "*.php\n");
        $this->workspace->mkdir('src');
        $this->workspace->write('src/.gitignore', "!Kernel.php\n");
        $matcher = new GitignoreMatcher();

        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('src/Kernel.php')));

        $this->workspace->write('src/.gitignore', "!Controller.php\n");

        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('src/Kernel.php')));
        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('src/Controller.php')));
    }

    public function testRefreshesResultsWhenAGitignoreFileAppearsOrDisappears(): void
    {
        $this->workspace->mkdir('src');
        $matcher = new GitignoreMatcher();

        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('src/Kernel.php')));

        $this->workspace->write('src/.gitignore', "Kernel.php\n");

        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('src/Kernel.php')));

        unlink($this->workspace->path('src/.gitignore'));

        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('src/Kernel.php')));
    }

    public function testReincludesEverythingBelowADirectoryRestoredByANegatedPattern(): void
    {
        $this->workspace->write('.gitignore', "custom/*\n!custom/static-plugins/\n");
        $this->workspace->mkdir('custom/static-plugins/Demo/src');
        $this->workspace->mkdir('custom/plugins');
        $matcher = new GitignoreMatcher();

        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('custom/static-plugins/Demo/composer.json')));
        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('custom/static-plugins/Demo/src/Theme.php')));
        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('custom/plugins/Legacy.php')));
    }

    public function testKeepsFilesIgnoredWhenTheirParentDirectoryItselfIsExcluded(): void
    {
        $this->workspace->write('.gitignore', "custom/\n!custom/static-plugins/\n");
        $this->workspace->mkdir('custom/static-plugins/Demo');
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('custom/static-plugins/Demo/composer.json')));
    }

    public function testFilterKeepsFilesRestoredByANegatedPattern(): void
    {
        $this->workspace->write('.gitignore', "custom/*\n!custom/static-plugins/\n");
        $this->workspace->mkdir('custom/static-plugins/Demo');
        $this->workspace->mkdir('custom/plugins');
        $matcher = new GitignoreMatcher();

        $files = [
            $this->workspace->path('custom/static-plugins/Demo/composer.json'),
            $this->workspace->path('custom/plugins/composer.json'),
        ];

        self::assertSame(
            [$this->workspace->path('custom/static-plugins/Demo/composer.json')],
            iterator_to_array($matcher->filter($files, $this->workspace->rootPath), false),
        );
    }

    public function testAppliesTheLastMatchingPattern(): void
    {
        $this->workspace->write('.gitignore', "*.log\n!important.log\n*.tmp.log\n");
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('debug.log')));
        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('important.log')));
        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('build.tmp.log')));
    }

    public function testNestedGitignoreFilesOverrideTheRootOnes(): void
    {
        $this->workspace->write('.gitignore', "*.php\n");
        $this->workspace->mkdir('src');
        $this->workspace->write('src/.gitignore', "!Kernel.php\n");
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('Foo.php')));
        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('src/Foo.php')));
        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('src/Kernel.php')));
    }

    public function testAnchorsPatternsContainingASlash(): void
    {
        $this->workspace->write('.gitignore', "/build\nsrc/cache\ncache\n");
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('build/app.php')));
        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('src/build/app.php')));
        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('src/cache/app.php')));
        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('var/deep/cache/app.php')));
    }

    public function testOnlyMatchesDirectoriesForPatternsEndingWithASlash(): void
    {
        $this->workspace->write('.gitignore', "cache/\n");
        $this->workspace->mkdir('var/cache');
        touch($this->workspace->path('cache'));
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('var/cache/app.php')));
        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('cache')));
    }

    public function testSupportsWildcardsAndCharacterClasses(): void
    {
        $this->workspace->write('.gitignore', "assets/**\n**/generated\nconfig[0-9].yaml\nlog?.txt\n");
        $this->workspace->mkdir('assets/build');
        $this->workspace->mkdir('src/deep/generated');
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('assets/build/app.js')));
        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('assets')));
        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('src/deep/generated/Dto.php')));
        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('config1.yaml')));
        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('config12.yaml')));
        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('loga.txt')));
    }

    public function testSkipsCommentsAndHonorsEscapedPrefixes(): void
    {
        $this->workspace->write('.gitignore', "# a comment\n\\#hash.txt\n\\!bang.txt\n");
        $matcher = new GitignoreMatcher();

        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('a.txt')));
        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('#hash.txt')));
        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('!bang.txt')));
    }

    public function testIgnoresUnescapedTrailingWhitespace(): void
    {
        $this->workspace->write('.gitignore', "trailing.txt   \nescaped.txt\\ \n");
        $matcher = new GitignoreMatcher();

        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('trailing.txt')));
        self::assertFalse($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('escaped.txt')));
        self::assertTrue($matcher->isIgnored($this->workspace->rootPath, $this->workspace->path('escaped.txt ')));
    }
}
