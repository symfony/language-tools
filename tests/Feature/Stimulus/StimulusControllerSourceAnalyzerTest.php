<?php

namespace Symfony\Lsp\Tests\Feature\Stimulus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerSource;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerSourceAnalyzer;
use Symfony\Lsp\Parser\JavaScript\JavaScriptTokenizer;

final class StimulusControllerSourceAnalyzerTest extends TestCase
{
    public function testExtractsMembersFromAnUnclosedControllerClass(): void
    {
        $source = $this->analyze(<<<'JS'
            export default class extends Controller {
                static targets = ['result'];

                open() {
                }
            JS);

        self::assertSame(
            [['result', 'target'], ['open', 'action']],
            array_map(static fn ($member): array => [$member->name, $member->kind->value], $source->members),
        );
    }

    public function testIgnoresBracesInStringsTemplatesAndCommentsWhenFindingTheClassBoundary(): void
    {
        $source = $this->analyze(<<<'JS'
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

        self::assertSame(['open'], array_map(static fn ($member): string => $member->name, $source->members));
    }

    #[DataProvider('regularExpressionProvider')]
    public function testIgnoresRegularExpressionContentsWhenFindingTheClassBoundary(string $regularExpression): void
    {
        $source = $this->analyze(<<<JS
            export default class extends Controller {
                open() {
                    const pattern = {$regularExpression};
                }

                close() {
                }
            }
            JS);

        self::assertSame(['open', 'close'], array_map(static fn ($member): string => $member->name, $source->members));
    }

    public function testIgnoresMembersInsideCommentsAndStrings(): void
    {
        $source = $this->analyze(<<<'JS'
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
            array_map(static fn ($member): array => [$member->name, $member->kind->value], $source->members),
        );
    }

    public function testIgnoresControlStructuresInsideMethodBodies(): void
    {
        $source = $this->analyze(<<<'JS'
            export default class extends Controller {
                open() {
                    if (ready) {
                    }
                    for (const item of items) {
                    }
                    while (pending) {
                    }
                    switch (mode) {
                    }
                }
            }
            JS);

        self::assertSame(['open'], array_map(static fn ($member): string => $member->name, $source->members));
    }

    public function testIgnoresMembersOfClassesNestedInsideTheControllerBody(): void
    {
        $source = $this->analyze(<<<'JS'
            export default class extends Controller {
                open() {
                    const helper = class { static targets = ['nested']; inner() {} };
                }
            }
            JS);

        self::assertSame(['open'], array_map(static fn ($member): string => $member->name, $source->members));
    }

    public function testIgnoresPropertiesAndAccessorsThatAreNotMethodDeclarations(): void
    {
        $source = $this->analyze(<<<'JS'
            export default class extends Controller {
                handler = function () {
                }

                get computed() {
                }

                static helper() {
                }

                open() {
                }
            }
            JS);

        self::assertSame(['open'], array_map(static fn ($member): string => $member->name, $source->members));
    }

    #[DataProvider('lazyMarkerProvider')]
    public function testDetectsTheLazyMarkerOnlyInComments(string $text, bool $expected): void
    {
        self::assertSame($expected, $this->analyze($text)->lazy);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function lazyMarkerProvider(): iterable
    {
        yield 'block comment' => ["/* stimulusFetch: 'lazy' */\nexport default class extends Controller {}", true];
        yield 'double-quoted block comment' => ["/* stimulusFetch: \"lazy\" */\nexport default class extends Controller {}", true];
        yield 'line comment' => ["// stimulusFetch: 'lazy'\nexport default class extends Controller {}", true];
        yield 'detached comment' => ["/* stimulusFetch: 'lazy' */\nconst mode = 'eager';\nexport default class extends Controller {}", true];
        yield 'comment inside the class' => ["export default class extends Controller {\n    // stimulusFetch: 'lazy'\n}", true];
        yield 'controller without a class' => ["/* stimulusFetch: 'lazy' */\nexport default 'csrf-protection-controller'", true];
        yield 'eager comment' => ["/* stimulusFetch: 'eager' */\nexport default class extends Controller {}", false];
        yield 'inside a string' => ["const doc = \"/* stimulusFetch: 'lazy' */\";\nexport default class extends Controller {}", false];
    }

    #[DataProvider('defaultExportProvider')]
    public function testResolvesTheClassBehindEveryDefaultExportShape(string $text): void
    {
        self::assertSame(['open'], array_map(static fn ($member): string => $member->name, $this->analyze($text)->members));
    }

    /** @return iterable<string, array{string}> */
    public static function defaultExportProvider(): iterable
    {
        yield 'anonymous exported class' => ["export default class extends Controller {\n    open() {}\n}"];
        yield 'named exported class' => ["export default class Widget extends Controller {\n    open() {}\n}"];
        yield 'exported binding' => ["class Widget extends Controller {\n    open() {}\n}\nexport default Widget;"];
        yield 'bundled anonymous class expression' => ["var _Class = class extends Controller {\n    open() {}\n};\nexport { _Class as default };"];
        yield 'bundled named class expression' => ["var Widget = class Widget extends Controller {\n    open() {}\n};\nexport { Component, Widget as default, helper };"];
    }

    public function testIgnoresClassesThatAreNotTheDefaultExport(): void
    {
        $source = $this->analyze(<<<'JS'
            var Helper = class extends Controller {
                helperAction() {}
            };
            var Widget = class extends Controller {
                open() {}
            };
            export { Widget as default };
            JS);

        self::assertSame(['open'], array_map(static fn ($member): string => $member->name, $source->members));
    }

    public function testCollectsStaticPropertiesAssignedAfterTheClassBody(): void
    {
        $source = $this->analyze(<<<'JS'
            var _Class = class extends Controller {
                open() {}
            };
            _Class.values = {
                hub: String,
                topics: Array
            };
            _Class.targets = ['panel'];
            export { _Class as default };
            JS);

        self::assertSame(
            [['open', 'action'], ['hub', 'value'], ['topics', 'value'], ['panel', 'target']],
            array_map(static fn ($member): array => [$member->name, $member->kind->value], $source->members),
        );
    }

    public function testInheritsMembersFromASuperclassDeclaredInTheSameFile(): void
    {
        $source = $this->analyze(<<<'JS'
            var _Class = class extends Controller {
                inherited() {}
            };
            _Class.values = { zoom: Number };
            var map_controller_default = class extends _Class {
                own() {}
            };
            export { map_controller_default as default };
            JS);

        self::assertSame(
            [['own', 'action'], ['inherited', 'action'], ['zoom', 'value']],
            array_map(static fn ($member): array => [$member->name, $member->kind->value], $source->members),
        );
    }

    public function testKeepsTheMostDerivedDeclarationOfAnOverriddenMember(): void
    {
        $source = $this->analyze(<<<'JS'
            var Base = class extends Controller {
                open() {}
            };
            var Widget = class extends Base {
                open() {}
            };
            export { Widget as default };
            JS);

        $members = $source->members;
        self::assertCount(1, $members);
        self::assertSame(4, $members[0]->range->start->line);
    }

    public function testReturnsNoMembersForAnIncompleteClassHeader(): void
    {
        $source = $this->analyze('export default class extends Controller');

        self::assertSame([], $source->members);
    }

    /** @return iterable<string, array{string}> */
    public static function regularExpressionProvider(): iterable
    {
        yield 'closing brace' => ['/}/'];
        yield 'quotes' => ['/[\'\"]/'];
    }

    private function analyze(string $text): StimulusControllerSource
    {
        return (new StimulusControllerSourceAnalyzer(new PositionConverter()))->analyze($text, (new JavaScriptTokenizer())->tokenize($text));
    }
}
