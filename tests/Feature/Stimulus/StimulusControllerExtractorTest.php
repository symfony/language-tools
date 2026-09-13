<?php

namespace Symfony\Lsp\Tests\Feature\Stimulus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Stimulus\JavaScriptSourceAnalyzer;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerDeclaration;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerExtractor;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerNameNormalizer;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\UriToPathConverter;

final class StimulusControllerExtractorTest extends TestCase
{
    public function testExtractsMembersFromAnUnclosedControllerClass(): void
    {
        $declaration = $this->extract(<<<'JS'
            export default class extends Controller {
                static targets = ['result'];

                open() {
                }
            JS);

        self::assertSame(
            [['result', 'target'], ['open', 'action']],
            array_map(static fn ($member): array => [$member->name, $member->kind->value], $declaration->members),
        );
    }

    public function testIgnoresBracesInStringsTemplatesAndCommentsWhenFindingTheClassBoundary(): void
    {
        $declaration = $this->extract(<<<'JS'
            export default class extends Controller {
                open() {
                    const string = "}";
                    const template = `<div>${value}</div> }`;
                    // }
                    /* } */
                }
            }

            class Helper {
                helper() {
                }
            }
            JS);

        self::assertSame(['open'], array_map(static fn ($member): string => $member->name, $declaration->members));
    }

    #[DataProvider('regularExpressionProvider')]
    public function testIgnoresRegularExpressionContentsWhenFindingTheClassBoundary(string $regularExpression): void
    {
        $declaration = $this->extract(<<<JS
            export default class extends Controller {
                open() {
                    const pattern = {$regularExpression};
                }

                close() {
                }
            }
            JS);

        self::assertSame(['open', 'close'], array_map(static fn ($member): string => $member->name, $declaration->members));
    }

    public function testIgnoresMembersInsideCommentsAndStrings(): void
    {
        $declaration = $this->extract(<<<'JS'
            export default class extends Controller {
                static targets = [
                    'result',
                    /* 'commentedTarget', */
                ];
                static values = {
                    query: String,
                    label: "value, stringValue: Number",
                    // commentedValue: Boolean,
                };

                open() {
                    const example = `
                        stringAction() {
                        }
                        static targets = ['stringTarget'];
                    `;
                }

                // commentedAction() {
                // }
            }
            JS);

        self::assertSame(
            [['result', 'target'], ['query', 'value'], ['label', 'value'], ['open', 'action']],
            array_map(static fn ($member): array => [$member->name, $member->kind->value], $declaration->members),
        );
    }

    public function testReturnsADeclarationForAnIncompleteClassHeader(): void
    {
        $declaration = $this->extract('export default class extends Controller');

        self::assertSame([], $declaration->members);
    }

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
        $project = new Project('/workspace', 'file:///workspace');
        $declarations = $this->createExtractor()->extract($project, 'file:///workspace/assets/controllers/example_controller.js', <<<'JS'
            /* stimulusFetch: 'lazy' */
            export default class extends Controller {
                connect() {
                    this.application.register('clipboard', Clipboard);
                }
            }
            JS);

        self::assertSame([['example', true], ['clipboard', false]], array_map(static fn ($declaration): array => [$declaration->name, $declaration->lazy], $declarations));
    }

    #[DataProvider('ignoredControllerPathProvider')]
    public function testIgnoresControllerNamedAssetsOutsideIndexableControllerDirectories(string $uri): void
    {
        self::assertSame([], $this->extractNames($uri, 'export default class extends Controller {}'));
    }

    /** @return iterable<string, array{string}> */
    public static function ignoredControllerPathProvider(): iterable
    {
        yield 'outside a controller directory' => ['file:///workspace/assets/Feature/scripts/feature_widget_controller.ts'];
        yield 'inside an excluded directory' => ['file:///workspace/assets/vendor/controllers/feature_widget_controller.ts'];
    }

    /** @return iterable<string, array{string}> */
    public static function regularExpressionProvider(): iterable
    {
        yield 'closing brace' => ['/}/'];
        yield 'quotes' => ['/[\'\"]/'];
    }

    private function extract(string $text): StimulusControllerDeclaration
    {
        $project = new Project('/workspace', 'file:///workspace');

        return $this->createExtractor()->extract($project, 'file:///workspace/assets/controllers/example_controller.js', $text)[0];
    }

    /** @return list<string> */
    private function extractNames(string $uri, string $text): array
    {
        $project = new Project('/workspace', 'file:///workspace');

        return array_map(static fn ($declaration): string => $declaration->name, $this->createExtractor()->extract($project, $uri, $text));
    }

    private function createExtractor(): StimulusControllerExtractor
    {
        return new StimulusControllerExtractor(new PositionConverter(), new ProjectPathResolver(new UriToPathConverter()), new JavaScriptSourceAnalyzer(), new StimulusControllerNameNormalizer());
    }
}
