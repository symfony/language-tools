<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\Dogfood\ResponseAssertions;

final class ResponseAssertionsTest extends TestCase
{
    private const ROOT = '/workspace/app';
    private const DOCUMENT = 'file:///workspace/app/templates/home.html.twig';
    private const POSITION = ['line' => 3, 'character' => 10];

    public function testProjectsCompletionLabelsInServerOrderKeepingDuplicates(): void
    {
        $result = [['label' => 'app_home', 'kind' => 12], ['label' => 'app_login'], ['label' => 'app_home']];

        self::assertSame(['app_home', 'app_login', 'app_home'], $this->project('completion', $result));
    }

    public function testProjectsCompletionListItems(): void
    {
        $result = ['isIncomplete' => false, 'items' => [['label' => 'app_home']]];

        self::assertSame(['app_home'], $this->project('completion', $result));
    }

    public function testProjectsHoverMarkdownWithNormalizedWhitespace(): void
    {
        $result = ['contents' => ['kind' => 'markdown', 'value' => "**Route** `app_home`\n\nPath: /home\n"]];

        self::assertSame(['**Route** `app_home` Path: /home'], $this->project('hover', $result));
    }

    public function testProjectsHoverMarkedStringLists(): void
    {
        $result = ['contents' => ['first', ['language' => 'php', 'value' => 'second']]];

        self::assertSame(['first second'], $this->project('hover', $result));
    }

    public function testProjectsDefinitionLocationsRelativeToTheProjectRoot(): void
    {
        $result = [
            ['uri' => 'file:///workspace/app/src/Controller/HomeController.php', 'range' => self::range(12, 4, 12, 24)],
            ['uri' => 'file:///workspace/app/config/routes.yaml', 'range' => self::range(0, 0, 0, 8)],
        ];

        self::assertSame(
            ['src/Controller/HomeController.php:12:4-12:24', 'config/routes.yaml:0:0-0:8'],
            $this->project('definition', $result),
        );
    }

    public function testProjectsASingleDefinitionLocation(): void
    {
        $result = ['uri' => 'file:///workspace/app/src/Controller/HomeController.php', 'range' => self::range(12, 4, 12, 24)];

        self::assertSame(['src/Controller/HomeController.php:12:4-12:24'], $this->project('definition', $result));
    }

    public function testProjectsLocationLinksThroughTheirSelectionRange(): void
    {
        $result = [[
            'targetUri' => 'file:///workspace/app/src/Controller/HomeController.php',
            'targetRange' => self::range(10, 0, 20, 1),
            'targetSelectionRange' => self::range(12, 4, 12, 24),
            'originSelectionRange' => self::range(3, 8, 3, 16),
        ]];

        self::assertSame(['src/Controller/HomeController.php:12:4-12:24'], $this->project('definition', $result));
    }

    public function testProjectsPercentEncodedLocations(): void
    {
        $result = [['uri' => 'file:///workspace/app/src/My%20Controller.php', 'range' => self::range(1, 0, 1, 3)]];

        self::assertSame(['src/My Controller.php:1:0-1:3'], $this->project('definition', $result));
    }

    public function testProjectsReferencesKeepingDuplicateLocations(): void
    {
        $location = ['uri' => 'file:///workspace/app/src/Controller/HomeController.php', 'range' => self::range(12, 4, 12, 24)];

        self::assertSame(
            ['src/Controller/HomeController.php:12:4-12:24', 'src/Controller/HomeController.php:12:4-12:24'],
            $this->project('references', [$location, $location]),
        );
    }

    public function testProjectsLocationsOutsideTheProjectWithoutMachinePaths(): void
    {
        $result = [['uri' => 'file:///elsewhere/vendor/acme/Bundle.php', 'range' => self::range(1, 0, 1, 3)]];

        self::assertSame(['<outside>/Bundle.php:1:0-1:3'], $this->project('definition', $result));
    }

    public function testProjectsOnlyDocumentLinksCoveringThePosition(): void
    {
        $result = [
            ['range' => self::range(3, 4, 3, 20), 'target' => 'file:///workspace/app/templates/base.html.twig'],
            ['range' => self::range(7, 0, 7, 12), 'target' => 'file:///workspace/app/templates/other.html.twig'],
        ];

        self::assertSame(['3:4-3:20=>templates/base.html.twig'], $this->project('documentLink', $result));
    }

    public function testProjectsDocumentLinkFragmentsAndExternalTargets(): void
    {
        $result = [
            ['range' => self::range(3, 4, 3, 20), 'target' => 'file:///workspace/app/src/Controller/HomeController.php#L42'],
            ['range' => self::range(3, 4, 3, 20), 'target' => 'https://symfony.com/doc/current/routing.html#creating-routes'],
            ['range' => self::range(3, 4, 3, 20), 'target' => 'file:///workspace/app/templates'],
        ];

        self::assertSame([
            '3:4-3:20=>src/Controller/HomeController.php#L42',
            '3:4-3:20=>https://symfony.com/doc/current/routing.html#creating-routes',
            '3:4-3:20=>templates',
        ], $this->project('documentLink', $result));
    }

    public function testProjectsDocumentLinksEndingOnThePositionAsNotCovering(): void
    {
        $result = [['range' => self::range(3, 0, 3, 10), 'target' => 'file:///workspace/app/templates/base.html.twig']];

        self::assertSame([], $this->project('documentLink', $result));
    }

    public function testProjectsCodeLensRangesAndTitles(): void
    {
        $result = [
            ['range' => self::range(9, 0, 9, 5), 'command' => ['title' => '2 Messenger handlers', 'command' => 'editor.action.showReferences', 'arguments' => []]],
            ['range' => self::range(30, 0, 30, 5)],
        ];

        self::assertSame(['9:0-9:5=>2 Messenger handlers', '30:0-30:5'], $this->project('codeLens', $result));
    }

    public function testProjectsPrepareRenameForBothAnswerShapes(): void
    {
        self::assertSame(['3:8-3:16'], $this->project('prepareRename', ['range' => self::range(3, 8, 3, 16), 'placeholder' => 'app_home']));
        self::assertSame(['3:8-3:16'], $this->project('prepareRename', self::range(3, 8, 3, 16)));
        self::assertSame(['defaultBehavior'], $this->project('prepareRename', ['defaultBehavior' => true]));
    }

    public function testProjectsEveryRenameEditWithItsReplacement(): void
    {
        $result = ['documentChanges' => [
            [
                'textDocument' => ['uri' => 'file:///workspace/app/config/routes.yaml', 'version' => null],
                'edits' => [['range' => self::range(0, 0, 0, 8), 'newText' => 'app_start', 'annotationId' => 'routeRename']],
            ],
            [
                'textDocument' => ['uri' => 'file:///workspace/app/templates/home.html.twig', 'version' => 1],
                'edits' => [
                    ['range' => self::range(3, 8, 3, 16), 'newText' => 'app_start'],
                    ['range' => self::range(9, 8, 9, 16), 'newText' => 'app_start'],
                ],
            ],
        ]];

        self::assertSame([
            'config/routes.yaml:0:0-0:8=>app_start',
            'templates/home.html.twig:3:8-3:16=>app_start',
            'templates/home.html.twig:9:8-9:16=>app_start',
        ], $this->project('rename', $result));
    }

    public function testProjectsRenameChangesAndEscapesMultilineReplacements(): void
    {
        $result = ['changes' => [
            'file:///workspace/app/translations/messages.en.yaml' => [['range' => self::range(4, 0, 4, 0), 'newText' => "\n'home.title': 'home.title'\n"]],
        ]];

        self::assertSame(["translations/messages.en.yaml:4:0-4:0=>\\n'home.title': 'home.title'\\n"], $this->project('rename', $result));
    }

    public function testProjectsRenameResourceOperations(): void
    {
        $result = ['documentChanges' => [
            ['kind' => 'create', 'uri' => 'file:///workspace/app/templates/new.html.twig'],
            ['kind' => 'rename', 'oldUri' => 'file:///workspace/app/templates/old.html.twig', 'newUri' => 'file:///workspace/app/templates/new.html.twig'],
        ]];

        self::assertSame([
            'create:templates/new.html.twig',
            'rename:templates/old.html.twig=>templates/new.html.twig',
        ], $this->project('rename', $result));
    }

    public function testProjectsCodeActionTitlesWithTheirEdits(): void
    {
        $result = [
            [
                'title' => 'Add translation "home.title" to messages.en.yaml',
                'kind' => 'quickfix',
                'edit' => ['documentChanges' => [[
                    'textDocument' => ['uri' => 'file:///workspace/app/translations/messages.en.yaml', 'version' => 1],
                    'edits' => [['range' => self::range(4, 0, 4, 0), 'newText' => "'home.title': 'home.title'\n"]],
                ]]],
            ],
            ['title' => 'Open the Symfony documentation', 'command' => ['title' => 'Open', 'command' => 'vscode.open']],
        ];

        self::assertSame([
            'Add translation "home.title" to messages.en.yaml=>translations/messages.en.yaml:4:0-4:0=>\'home.title\': \'home.title\'\\n',
            'Open the Symfony documentation',
        ], $this->project('codeAction', $result));
    }

    public function testProjectsDiagnosticsWithoutTheirMessages(): void
    {
        $result = [
            ['range' => self::range(3, 8, 3, 16), 'severity' => 2, 'code' => 'translation.not_found', 'source' => 'symfony', 'message' => 'Unknown translation "home.title".'],
            ['range' => self::range(3, 8, 3, 16), 'severity' => 2, 'code' => 'translation.not_found', 'message' => 'Unknown translation "home.title".'],
            ['range' => self::range(9, 0, 9, 4), 'severity' => 1, 'message' => 'Broken.'],
        ];

        self::assertSame([
            'translation.not_found:warning:3:8-3:16',
            'translation.not_found:warning:3:8-3:16',
            '<none>:error:9:0-9:4',
        ], $this->project('diagnostics', $result));
    }

    public function testProjectsMissingAnswersAsNoEntries(): void
    {
        foreach (ResponseAssertions::METHODS as $method) {
            self::assertSame([], $this->project($method, null), $method);
        }
    }

    public function testProjectsMalformedAnswersAsInvalidEntries(): void
    {
        self::assertSame(['<invalid>'], $this->project('completion', [['detail' => 'no label']]));
        self::assertSame(['<invalid>'], $this->project('completion', ['items' => 'nonsense']));
        self::assertSame(['<invalid>'], $this->project('hover', ['contents' => ['kind' => 'markdown']]));
        self::assertSame(['<invalid>'], $this->project('definition', [['uri' => 'file:///workspace/app/src/A.php', 'range' => ['start' => ['line' => '1', 'character' => 0], 'end' => ['line' => 1, 'character' => 2]]]]));
        self::assertSame(['<invalid>'], $this->project('references', ['nonsense']));
        self::assertSame(['<invalid>'], $this->project('documentLink', [['range' => self::range(3, 0, 3, 20)]]));
        self::assertSame(['<invalid>'], $this->project('codeLens', [['command' => ['title' => 'orphan']]]));
        self::assertSame(['<invalid>'], $this->project('prepareRename', ['placeholder' => 'app_home']));
        self::assertSame(['<invalid>'], $this->project('rename', ['changes' => ['file:///workspace/app/src/A.php' => [['newText' => 'app_start']]]]));
        self::assertSame(['<invalid>'], $this->project('codeAction', [['kind' => 'quickfix']]));
        self::assertSame(['<invalid>'], $this->project('diagnostics', [['severity' => 1, 'code' => 'a']]));
    }

    public function testRejectsUnknownMethods(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported assertion method "textDocument/formatting".');

        $this->project('textDocument/formatting', null);
    }

    public function testAcceptsFullyQualifiedMethodNames(): void
    {
        self::assertSame(['app_home'], $this->project('textDocument/completion', [['label' => 'app_home']]));
    }

    public function testEqualsIgnoresOrderButRequiresEveryAnswer(): void
    {
        $assertions = new ResponseAssertions();

        self::assertSame([], $assertions->compare('completion', ['b', 'a'], ['equals' => ['a', 'b']]));
    }

    public function testEqualsReportsAWrongSameProjectTarget(): void
    {
        $assertions = new ResponseAssertions();

        $failures = $assertions->compare(
            'definition',
            ['src/Controller/OtherController.php:12:4-12:24'],
            ['equals' => ['src/Controller/HomeController.php:12:4-12:24']],
        );

        self::assertSame([
            'Expected the definition answers to contain "src/Controller/HomeController.php:12:4-12:24", which is missing.',
            'Expected the definition answers to match exactly, got 1 unexpected answer(s).',
        ], $failures);
    }

    public function testEqualsDetectsAChangedRangeInARenameEdit(): void
    {
        $assertions = new ResponseAssertions();
        $expectation = ['equals' => ['config/routes.yaml:0:0-0:8=>app_start']];

        self::assertSame([], $assertions->compare('rename', ['config/routes.yaml:0:0-0:8=>app_start'], $expectation));
        self::assertCount(2, $assertions->compare('rename', ['config/routes.yaml:0:0-0:9=>app_start'], $expectation));
        self::assertCount(2, $assertions->compare('rename', ['config/routes.yaml:0:0-0:8=>app_started'], $expectation));
    }

    public function testEqualsTreatsDuplicatesAsSignificant(): void
    {
        $assertions = new ResponseAssertions();

        self::assertSame(
            ['Expected the references answers to contain "src/A.php:1:0-1:4" 2 time(s), got 1.'],
            $assertions->compare('references', ['src/A.php:1:0-1:4'], ['equals' => ['src/A.php:1:0-1:4', 'src/A.php:1:0-1:4']]),
        );
        self::assertSame(
            ['Expected the references answers to contain "src/A.php:1:0-1:4" 1 time(s), got 2.'],
            $assertions->compare('references', ['src/A.php:1:0-1:4', 'src/A.php:1:0-1:4'], ['equals' => ['src/A.php:1:0-1:4']]),
        );
        self::assertSame([], $assertions->compare('references', ['src/A.php:1:0-1:4', 'src/A.php:1:0-1:4'], ['equals' => ['src/A.php:1:0-1:4', 'src/A.php:1:0-1:4']]));
    }

    public function testEqualsWithoutExpectedAnswersRequiresAnEmptyResult(): void
    {
        $assertions = new ResponseAssertions();

        self::assertSame([], $assertions->compare('completion', [], ['equals' => []]));
        self::assertSame(
            ['Expected no completion answer, got 2.'],
            $assertions->compare('completion', ['app_home', 'app_login'], ['equals' => []]),
        );
    }

    public function testIncludesRequiresEveryListedEntry(): void
    {
        $assertions = new ResponseAssertions();

        self::assertSame([], $assertions->compare('completion', ['a', 'b', 'c'], ['includes' => ['a', 'c']]));
        self::assertSame(
            ['Expected the completion answers to contain "d", which is missing.'],
            $assertions->compare('completion', ['a', 'b', 'c'], ['includes' => ['a', 'd']]),
        );
        self::assertSame(
            ['Expected the completion answers to contain "a" 2 time(s), got 1.'],
            $assertions->compare('completion', ['a', 'b'], ['includes' => ['a', 'a']]),
        );
    }

    public function testExcludesRejectsReturnedEntries(): void
    {
        $assertions = new ResponseAssertions();

        self::assertSame(
            ['Expected the definition answers not to contain "vendor/acme/Bundle.php:1:0-1:4", which was returned 1 time(s).'],
            $assertions->compare(
                'definition',
                ['src/A.php:1:0-1:4', 'vendor/acme/Bundle.php:1:0-1:4'],
                ['includes' => ['src/A.php:1:0-1:4'], 'excludes' => ['vendor/acme/Bundle.php:1:0-1:4']],
            ),
        );
    }

    public function testHoverMatchesSubstringsOfTheNormalizedMarkdown(): void
    {
        $assertions = new ResponseAssertions();
        $actual = ['**Route** `app_home` Path: /home'];

        self::assertSame([], $assertions->compare('hover', $actual, ['includes' => ["**Route**\n`app_home`"], 'excludes' => ['Deprecated']]));
        self::assertSame(
            ['Expected the hover text to contain "Path: /admin", which is missing.'],
            $assertions->compare('hover', $actual, ['includes' => ['Path: /admin']]),
        );
        self::assertSame(
            ['Expected the hover text not to contain "app_home", which is present.'],
            $assertions->compare('hover', $actual, ['includes' => ['**Route**'], 'excludes' => ['app_home']]),
        );
    }

    public function testFailuresNeverRevealAnswersTheScenarioDidNotExpect(): void
    {
        $assertions = new ResponseAssertions();

        $failures = [
            ...$assertions->compare('hover', ['**Route** `secret_internal_route` Path: /secret'], ['equals' => ['**Route** `app_home`']]),
            ...$assertions->compare('hover', ['**Route** `secret_internal_route` Path: /secret'], ['includes' => ['app_home']]),
            ...$assertions->compare('rename', ['src/Secret.php:1:0-1:4=>secret_replacement'], ['equals' => ['src/A.php:1:0-1:4=>app_start']]),
            ...$assertions->compare('completion', ['secret_internal_route'], ['equals' => []]),
        ];

        self::assertNotSame([], $failures);
        foreach ($failures as $failure) {
            self::assertStringNotContainsString('secret', $failure);
        }
    }

    public function testRejectsExpectationsWithoutEqualsOrANonEmptyIncludes(): void
    {
        $assertions = new ResponseAssertions();

        self::assertSame(
            ['The completion expectation must assert "equals" or a non-empty "includes".'],
            $assertions->compare('completion', ['a'], []),
        );
        self::assertSame(
            ['The completion expectation must assert "equals" or a non-empty "includes".'],
            $assertions->compare('completion', ['a'], ['includes' => [], 'excludes' => ['b']]),
        );
    }

    public function testRejectsUnsupportedOrMalformedExpectationKeys(): void
    {
        $assertions = new ResponseAssertions();

        self::assertSame([
            'The completion expectation uses the unsupported key "minCount".',
            'The completion expectation key "includes" must be a list of strings.',
            'The completion expectation must assert "equals" or a non-empty "includes".',
        ], $assertions->compare('completion', ['a'], ['minCount' => 1, 'includes' => ['a' => 'b']]));

        self::assertSame([
            'The completion expectation key "equals" must be a list of strings.',
            'The completion expectation must assert "equals" or a non-empty "includes".',
        ], $assertions->compare('completion', ['a'], ['equals' => ['a', 2]]));
    }

    /**
     * @param array<array-key, mixed>|string|null $result
     *
     * @return list<string>
     */
    private function project(string $method, array|string|null $result): array
    {
        return (new ResponseAssertions())->project($method, $result, self::ROOT, self::DOCUMENT, self::POSITION);
    }

    /**
     * @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}
     */
    private static function range(int $startLine, int $startCharacter, int $endLine, int $endCharacter): array
    {
        return [
            'start' => ['line' => $startLine, 'character' => $startCharacter],
            'end' => ['line' => $endLine, 'character' => $endCharacter],
        ];
    }
}
