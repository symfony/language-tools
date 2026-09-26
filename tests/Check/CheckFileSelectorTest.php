<?php

namespace Symfony\Lsp\Tests\Check;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Check\CheckFileSelector;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\GlobPatternCompiler;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Tests\Support\ProjectPaths;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class CheckFileSelectorTest extends TestCase
{
    private TestWorkspace $workspace;
    private CheckFileSelector $selector;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-check-selector-');
        $this->workspace->mkdir('project/src/Admin', 'project/templates/admin');
        $this->workspace->write('project/page.twig', '');
        $this->workspace->write('project/src/Controller.php', '<?php');
        $this->workspace->write('project/src/Admin/Controller.php', '<?php');
        $this->workspace->write('project/src/Admin/view.twig', '');
        $this->workspace->write('project/templates/page.twig', '');
        $this->workspace->write('project/templates/admin/page.twig', '');

        $uriToPathConverter = new UriToPathConverter();
        $projectConfiguration = new ProjectConfiguration($uriToPathConverter, new AnalysisSettings());
        $projectConfiguration->load([['uri' => $uriToPathConverter->toUri($this->root())]]);
        $projects = new ProjectRegistry();
        $projects->replace([new Project($this->root(), $uriToPathConverter->toUri($this->root()))]);
        $globPatterns = new GlobPatternCompiler();
        $this->selector = new CheckFileSelector(
            $projects,
            ProjectPaths::policy(),
            ProjectPaths::enumerator(new ProjectFileScopeRegistry($globPatterns)),
            $uriToPathConverter,
            $projectConfiguration,
            $globPatterns,
        );
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    private function root(): string
    {
        return $this->workspace->path('project');
    }

    public function testRejectsASelectedFileResolvingOutsideTheProject(): void
    {
        if ('Windows' === \PHP_OS_FAMILY || !\function_exists('symlink')) {
            self::markTestSkipped('File symlinks are not supported in this environment.');
        }
        $outside = $this->workspace->write('outside/Service.php', '<?php');
        if (!symlink($outside, $this->workspace->path('project/src/Linked.php'))) {
            self::markTestSkipped('Unable to create a file symlink in this environment.');
        }

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The application file "src/Linked.php" resolves outside its Symfony project.');

        $this->selector->select($this->root(), ['src/Linked*.php']);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('patterns')]
    public function testMatchesSingleSegmentAndRecursivePatterns(string $pattern, array $expected): void
    {
        self::assertSame($expected, array_column($this->selector->select($this->root(), [$pattern]), 'workspacePath'));
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

    public function testSelectsApplicationFilesInDirectoriesNamedLikeDependencyDirectories(): void
    {
        $this->workspace->mkdir('project/templates/vendor', 'project/vendor/acme');
        $this->workspace->write('project/templates/vendor/show.html.twig', '');
        $this->workspace->write('project/vendor/acme/Thing.php', '<?php');

        self::assertSame(
            ['templates/vendor/show.html.twig'],
            array_column($this->selector->select($this->root(), ['templates/vendor/show.html.twig']), 'workspacePath'),
        );

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The selected file "vendor/acme/Thing.php" is in a directory Composer, npm or Git owns.');

        $this->selector->select($this->root(), ['vendor/acme/Thing.php']);
    }
}
