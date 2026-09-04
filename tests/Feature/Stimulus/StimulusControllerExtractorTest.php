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

    #[DataProvider('ignoredControllerPathProvider')]
    public function testIgnoresControllerNamedAssetsOutsideIndexableControllerDirectories(string $uri): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $extractor = new StimulusControllerExtractor(new PositionConverter(), new ProjectPathResolver(new UriToPathConverter()), new JavaScriptSourceAnalyzer(), new StimulusControllerNameNormalizer());

        self::assertSame([], $extractor->extract(
            $project,
            $uri,
            'export default class extends Controller {}',
        ));
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
        $extractor = new StimulusControllerExtractor(new PositionConverter(), new ProjectPathResolver(new UriToPathConverter()), new JavaScriptSourceAnalyzer(), new StimulusControllerNameNormalizer());

        return $extractor->extract($project, 'file:///workspace/assets/controllers/example_controller.js', $text)[0];
    }
}
