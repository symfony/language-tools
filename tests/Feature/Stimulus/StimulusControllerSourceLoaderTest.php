<?php

namespace Symfony\Lsp\Tests\Feature\Stimulus;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerSourceAnalyzer;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerSourceLoader;
use Symfony\Lsp\Feature\Stimulus\StimulusMemberKind;
use Symfony\Lsp\Parser\JavaScript\JavaScriptTokenizer;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class StimulusControllerSourceLoaderTest extends TestCase
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

    public function testAnalyzesControllerFilesOutsideTheIndexedProjectScope(): void
    {
        $path = $this->workspace->write('vendor/acme/ux-widget/assets/dist/widget_controller.js', <<<'JS'
            export default class extends Controller {
                static targets = ['panel'];
                static values = { url: { type: String, default: '' }, count: Number };

                connect() {
                }

                refresh() {
                    if (ready) {
                    }
                }
            }
            JS);

        $source = $this->loader()->load($path);

        self::assertNotNull($source);
        self::assertSame(['refresh'], $source->memberNames(StimulusMemberKind::Action));
        self::assertSame(['panel'], $source->memberNames(StimulusMemberKind::Target));
        self::assertSame(['count', 'url'], $source->memberNames(StimulusMemberKind::Value));
    }

    public function testReturnsNullForUnreadableFiles(): void
    {
        self::assertNull($this->loader()->load($this->workspace->path('assets/controllers/missing_controller.js')));
    }

    public function testReanalyzesAControllerFileAfterItChanges(): void
    {
        $path = $this->workspace->write('assets/controllers/widget_controller.js', "export default class extends Controller {\n    open() {}\n}");
        $loader = $this->loader();

        self::assertSame(['open'], $loader->load($path)?->memberNames(StimulusMemberKind::Action));

        touch($path, time() + 2);
        file_put_contents($path, "export default class extends Controller {\n    close() {}\n}");
        touch($path, time() + 2);

        self::assertSame(['close'], $loader->load($path)?->memberNames(StimulusMemberKind::Action));
    }

    private function loader(): StimulusControllerSourceLoader
    {
        return new StimulusControllerSourceLoader(new JavaScriptTokenizer(), new StimulusControllerSourceAnalyzer(new PositionConverter()));
    }
}
