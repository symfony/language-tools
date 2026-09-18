<?php

namespace Symfony\Lsp\Tests\Feature\Stimulus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerDeclaration;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerExtractor;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerNameNormalizer;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerSourceAnalyzer;
use Symfony\Lsp\Parser\JavaScript\JavaScriptTokenizer;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Tests\Support\ProjectPaths;

final class StimulusControllerExtractorTest extends TestCase
{
    #[DataProvider('manualRegistrationProvider')]
    public function testDeclaresManuallyRegisteredControllers(string $text): void
    {
        self::assertSame(['clipboard'], $this->extractNames('file:///workspace/assets/app/stimulus_bootstrap.js', $text));
    }

    /** @return iterable<string, array{string}> */
    public static function manualRegistrationProvider(): iterable
    {
        yield 'stimulus bundle application' => [<<<'JS'
            import { startStimulusApp } from '@symfony/stimulus-bundle';
            import Clipboard from 'stimulus-clipboard';

            const app = startStimulusApp();
            app.register('clipboard', Clipboard);
            JS];
        yield 'exported stimulus bridge application' => [<<<'JS'
            import { startStimulusApp } from '@symfony/stimulus-bridge';

            export const app = startStimulusApp(require.context('./controllers', true, /\.[jt]sx?$/));
            app.register('clipboard', Clipboard);
            JS];
        yield 'started hotwired application' => [<<<'JS'
            import { Application } from '@hotwired/stimulus';

            const application = Application.start();
            application.register('clipboard', Clipboard);
            JS];
        yield 'registration split over several lines' => [<<<'JS'
            const app = startStimulusApp();
            app.register(
                'clipboard',
                Clipboard,
            );
            JS];
    }

    public function testKeepsManuallyRegisteredIdentifiersVerbatim(): void
    {
        self::assertSame(['clip_board'], $this->extractNames('file:///workspace/assets/app/stimulus_bootstrap.js', <<<'JS'
            const app = startStimulusApp();
            app.register('clip_board', Clipboard);
            JS));
    }

    public function testIgnoresRegistrationsOnUnrelatedReceivers(): void
    {
        self::assertSame([], $this->extractNames('file:///workspace/assets/app/stimulus_bootstrap.js', <<<'JS'
            const app = startStimulusApp();
            const registry = new Container();
            registry.register('unrelated', Thing);
            this.container.register('service', Service);
            JS));
    }

    public function testIgnoresRegistrationsOutsideAssetDirectories(): void
    {
        self::assertSame([], $this->extractNames('file:///workspace/public/build/bootstrap.js', <<<'JS'
            const app = startStimulusApp();
            app.register('clipboard', Clipboard);
            JS));
    }

    public function testMarksManuallyRegisteredControllersAsEager(): void
    {
        $declarations = $this->extractDeclarations('file:///workspace/assets/controllers/example_controller.js', <<<'JS'
            /* stimulusFetch: 'lazy' */
            export default class extends Controller {
                connect() {
                    this.application.register('clipboard', Clipboard);
                }
            }
            JS);

        self::assertSame([['example', true], ['clipboard', false]], array_map(static fn ($declaration): array => [$declaration->name, $declaration->lazy], $declarations));
    }

    public function testIgnoresControllersOutsideIndexableControllerDirectories(): void
    {
        self::assertSame([], $this->extractNames('file:///workspace/assets/Feature/scripts/feature_widget_controller.ts', 'export default class extends Controller {}'));
    }

    public function testIgnoresControllersTheProjectDeclaresAsGenerated(): void
    {
        $root = sys_get_temp_dir().'/symfony-lsp-stimulus-'.bin2hex(random_bytes(6));
        mkdir($root.'/assets/vendor/controllers', 0777, true);
        file_put_contents($root.'/.gitignore', "/assets/vendor/\n");
        $path = $root.'/assets/vendor/controllers/feature_widget_controller.ts';
        file_put_contents($path, 'export default class extends Controller {}');

        try {
            self::assertSame([], $this->extractNames((new UriToPathConverter())->toUri($path), 'export default class extends Controller {}', $root));
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    /** @return list<string> */
    private function extractNames(string $uri, string $text, ?string $rootPath = null): array
    {
        return array_map(static fn ($declaration): string => $declaration->name, $this->extractDeclarations($uri, $text, $rootPath));
    }

    /** @return list<StimulusControllerDeclaration> */
    private function extractDeclarations(string $uri, string $text, ?string $rootPath = null): array
    {
        $extractor = new StimulusControllerExtractor(new PositionConverter(), ProjectPaths::resolver(), new StimulusControllerNameNormalizer(), new StimulusControllerSourceAnalyzer(new PositionConverter()));
        $project = null === $rootPath
            ? new Project('/workspace', 'file:///workspace')
            : new Project($rootPath, (new UriToPathConverter())->toUri($rootPath));

        return $extractor->extract($project, $uri, $text, (new JavaScriptTokenizer())->tokenize($text));
    }
}
