<?php

namespace Symfony\Lsp\Tests\Check;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Check\CheckFileSelector;
use Symfony\Lsp\Index\SourceFileEnumerator;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\GitignoreMatcher;
use Symfony\Lsp\Project\GlobPatternCompiler;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

final class CheckFileSelectorTest extends TestCase
{
    private string $directory;
    private CheckFileSelector $selector;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/symfony-lsp-check-selector-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/src/Admin', 0777, true);
        mkdir($this->directory.'/templates/admin', 0777, true);
        file_put_contents($this->directory.'/page.twig', '');
        file_put_contents($this->directory.'/src/Controller.php', '<?php');
        file_put_contents($this->directory.'/src/Admin/Controller.php', '<?php');
        file_put_contents($this->directory.'/src/Admin/view.twig', '');
        file_put_contents($this->directory.'/templates/page.twig', '');
        file_put_contents($this->directory.'/templates/admin/page.twig', '');

        $uriToPathConverter = new UriToPathConverter();
        $projectConfiguration = new ProjectConfiguration($uriToPathConverter, new AnalysisSettings());
        $projectConfiguration->load([['uri' => $uriToPathConverter->toUri($this->directory)]]);
        $projects = new ProjectRegistry();
        $projects->replace([new Project($this->directory, $uriToPathConverter->toUri($this->directory))]);
        $globPatterns = new GlobPatternCompiler();
        $this->selector = new CheckFileSelector(
            $projects,
            new SourceFileEnumerator(new GitignoreMatcher(), new ProjectFileScopeRegistry($globPatterns)),
            $uriToPathConverter,
            $projectConfiguration,
            $globPatterns,
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->directory, $this->directory.'-outside.php']);
    }

    public function testRejectsASelectedFileResolvingOutsideTheProject(): void
    {
        if ('Windows' === \PHP_OS_FAMILY || !\function_exists('symlink')) {
            self::markTestSkipped('File symlinks are not supported in this environment.');
        }
        file_put_contents($this->directory.'-outside.php', '<?php');
        if (!symlink($this->directory.'-outside.php', $this->directory.'/src/Linked.php')) {
            self::markTestSkipped('Unable to create a file symlink in this environment.');
        }

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The application file "src/Linked.php" resolves outside its Symfony project.');

        $this->selector->select($this->directory, ['src/Linked*.php']);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('patterns')]
    public function testMatchesSingleSegmentAndRecursivePatterns(string $pattern, array $expected): void
    {
        self::assertSame($expected, array_column($this->selector->select($this->directory, [$pattern]), 'workspacePath'));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function patterns(): iterable
    {
        yield 'single star matches one root segment' => ['*.twig', ['page.twig']];
        yield 'single star matches one nested segment' => ['src/*.php', ['src/Controller.php']];
        yield 'leading double star crosses directories' => ['**.twig', [
            'page.twig',
            'src/Admin/view.twig',
            'templates/admin/page.twig',
            'templates/page.twig',
        ]];
        yield 'embedded double star crosses directories' => ['src/**.php', [
            'src/Admin/Controller.php',
            'src/Controller.php',
        ]];
        yield 'double star path segment matches zero or more directories' => ['templates/**/page.twig', [
            'templates/admin/page.twig',
            'templates/page.twig',
        ]];
    }
}
