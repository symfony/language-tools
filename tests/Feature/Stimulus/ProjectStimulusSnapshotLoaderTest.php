<?php

namespace Symfony\Lsp\Tests\Feature\Stimulus;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Stimulus\ProjectStimulusSnapshotLoader;
use Symfony\Lsp\Feature\Stimulus\StimulusController;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerSourceAnalyzer;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerSourceLoader;
use Symfony\Lsp\Feature\Stimulus\StimulusIndexRegistry;
use Symfony\Lsp\Parser\JavaScript\JavaScriptTokenizer;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class ProjectStimulusSnapshotLoaderTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace();
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testDerivesControllerMembersFromTheReferencedSourceFile(): void
    {
        $sourcePath = $this->workspace->write('assets/controllers/search_controller.js', <<<'JS'
            export default class extends Controller {
                static targets = ['results'];
                static outlets = ['dialog'];
                static classes = ['loading'];
                static values = { url: { type: String, default: '' }, page: Number };

                connect() {
                }

                open() {
                }
            }
            JS);
        $indexes = new StimulusIndexRegistry();
        $project = new Project($this->workspace->path(), 'file://'.$this->workspace->path());

        $this->loader($indexes)->load($project, [
            'complete' => true,
            'controllers' => [['name' => 'search', 'sourcePath' => $sourcePath, 'lazy' => null, 'vendor' => false]],
        ]);

        $index = $indexes->forProject($project);
        self::assertTrue($index->isComplete());
        $controller = $index->controller('search');
        self::assertInstanceOf(StimulusController::class, $controller);
        self::assertSame($sourcePath, $controller->sourcePath);
        self::assertFalse($controller->lazy);
        self::assertSame(['open'], $controller->actions);
        self::assertSame(['results'], $controller->targets);
        self::assertSame(['page', 'url'], $controller->values);
        self::assertSame(['dialog'], $controller->outlets);
        self::assertSame(['loading'], $controller->classes);
    }

    public function testPrefersTheConfiguredLoadingModeOverTheSourceMarker(): void
    {
        $sourcePath = $this->workspace->write('assets/controllers/widget_controller.js', "/* stimulusFetch: 'lazy' */\nexport default class extends Controller {}");
        $indexes = new StimulusIndexRegistry();
        $project = new Project($this->workspace->path(), 'file://'.$this->workspace->path());

        $this->loader($indexes)->load($project, [
            'complete' => true,
            'controllers' => [['name' => 'widget', 'sourcePath' => $sourcePath, 'lazy' => false, 'vendor' => true]],
        ]);

        self::assertFalse($indexes->forProject($project)->controller('widget')?->lazy);
    }

    public function testFallsBackToTheSourceMarkerWhenTheLoadingModeIsUnknown(): void
    {
        $sourcePath = $this->workspace->write('assets/controllers/widget_controller.js', "/* stimulusFetch: 'lazy' */\nexport default class extends Controller {}");
        $indexes = new StimulusIndexRegistry();
        $project = new Project($this->workspace->path(), 'file://'.$this->workspace->path());

        $this->loader($indexes)->load($project, [
            'complete' => true,
            'controllers' => [['name' => 'widget', 'sourcePath' => $sourcePath, 'lazy' => null, 'vendor' => false]],
        ]);

        self::assertTrue($indexes->forProject($project)->controller('widget')?->lazy);
    }

    public function testKeepsControllersWhoseSourceFileCannotBeRead(): void
    {
        $indexes = new StimulusIndexRegistry();
        $project = new Project($this->workspace->path(), 'file://'.$this->workspace->path());

        $this->loader($indexes)->load($project, [
            'complete' => true,
            'controllers' => [['name' => 'missing', 'sourcePath' => $this->workspace->path('assets/controllers/missing_controller.js'), 'lazy' => null, 'vendor' => false]],
        ]);

        $controller = $indexes->forProject($project)->controller('missing');
        self::assertInstanceOf(StimulusController::class, $controller);
        self::assertSame([], $controller->actions);
        self::assertFalse($controller->lazy);
    }

    public function testMapsContainerSourcePathsToTheHost(): void
    {
        $configuration = new RuntimeConfiguration();
        $configuration->configure(['containerProjectRoot' => '/app']);
        $indexes = new StimulusIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace');

        $this->loader($indexes, $configuration)->load($project, [
            'complete' => true,
            'controllers' => [['name' => 'search', 'sourcePath' => '/app/assets/controllers/search_controller.js', 'lazy' => false, 'vendor' => false]],
        ]);

        self::assertSame(
            '/workspace/assets/controllers/search_controller.js',
            $indexes->forProject($project)->controller('search')?->sourcePath,
        );
    }

    private function loader(StimulusIndexRegistry $indexes, ?RuntimeConfiguration $configuration = null): ProjectStimulusSnapshotLoader
    {
        return new ProjectStimulusSnapshotLoader(
            $indexes,
            new ContainerPathMapper($configuration ?? new RuntimeConfiguration()),
            new StimulusControllerSourceLoader(new JavaScriptTokenizer(), new StimulusControllerSourceAnalyzer(new PositionConverter())),
        );
    }
}
