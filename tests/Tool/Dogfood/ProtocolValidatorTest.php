<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\TestWorkspace;
use Symfony\Lsp\Tools\Dogfood\ProtocolValidator;

final class ProtocolValidatorTest extends TestCase
{
    private TestWorkspace $workspace;
    private string $project;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-dogfood-');
        $this->project = $this->workspace->path('app');
        $this->write('src/Controller.php', "<?php\n\nclass Controller\n{\n}\n");
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testAcceptsApplicationOwnedLocationsWithValidRanges(): void
    {
        $result = [['uri' => $this->uri('src/Controller.php'), 'range' => self::range(2, 6, 2, 16)]];

        self::assertSame([], $this->validate('textDocument/definition', $result));
    }

    public function testRejectsInvertedRanges(): void
    {
        $result = [['uri' => $this->uri('src/Controller.php'), 'range' => self::range(3, 2, 2, 0)]];

        $violations = $this->validate('textDocument/definition', $result);

        self::assertCount(1, $violations);
        self::assertStringContainsString('Invalid range', $violations[0]);
    }

    public function testRejectsNegativePositions(): void
    {
        self::assertCount(1, $this->validate('textDocument/hover', ['range' => self::range(-1, 0, 0, 0)]));
    }

    public function testRejectsMalformedRanges(): void
    {
        self::assertCount(1, $this->validate('textDocument/hover', ['range' => ['start' => ['line' => '2', 'character' => 0], 'end' => ['line' => 2, 'character' => 4]]]));
        self::assertCount(1, $this->validate('textDocument/hover', ['range' => ['start' => ['line' => 2, 'character' => 0]]]));
        self::assertCount(1, $this->validate('textDocument/hover', ['range' => 'nonsense']));
    }

    public function testRejectsLocationsOutsideTheApplication(): void
    {
        $this->workspace->write('outside.php', "<?php\n");
        $result = [['uri' => 'file://'.$this->workspace->path('outside.php'), 'range' => self::range(0, 0, 0, 1)]];

        $violations = $this->validate('textDocument/references', $result);

        self::assertCount(1, $violations);
        self::assertStringContainsString('outside the application', $violations[0]);
    }

    public function testRejectsLexicallyContainedLocationsThatEscapeTheApplication(): void
    {
        $result = [['uri' => $this->uri('../outside.php'), 'range' => self::range(0, 0, 0, 1)]];

        $violations = $this->validate('textDocument/definition', $result);

        self::assertCount(1, $violations);
        self::assertStringContainsString('outside the application', $violations[0]);
    }

    public function testRejectsLocationsReachedThroughASymbolicLinkOutOfTheApplication(): void
    {
        $this->workspace->write('outside/Controller.php', "<?php\n");
        if (!@symlink($this->workspace->path('outside'), $this->workspace->path('app/linked'))) {
            self::markTestSkipped('Symbolic links are unavailable.');
        }
        $result = [['uri' => $this->uri('linked/Controller.php'), 'range' => self::range(0, 0, 0, 1)]];

        $violations = $this->validate('textDocument/definition', $result);

        self::assertCount(1, $violations);
        self::assertStringContainsString('escapes the application through a symbolic link', $violations[0]);
    }

    public function testAcceptsLocationsReachedThroughASymbolicLinkInsideTheApplication(): void
    {
        $this->write('lib/Controller.php', "<?php\n");
        if (!@symlink($this->project.'/lib', $this->project.'/linked')) {
            self::markTestSkipped('Symbolic links are unavailable.');
        }
        $result = [['uri' => $this->uri('linked/Controller.php'), 'range' => self::range(0, 0, 0, 1)]];

        self::assertSame([], $this->validate('textDocument/definition', $result));
    }

    public function testRejectsLocationsThatDoNotExist(): void
    {
        $result = [['uri' => $this->uri('src/Missing.php'), 'range' => self::range(0, 0, 0, 1)]];

        self::assertSame(['Location "src/Missing.php" does not exist.'], $this->validate('textDocument/definition', $result));
    }

    public function testRejectsLocationsPointingAtADirectory(): void
    {
        $result = [['uri' => $this->uri('src'), 'range' => self::range(0, 0, 0, 0)]];

        self::assertSame(['Location "src" is a directory.'], $this->validate('textDocument/definition', $result));
    }

    public function testAcceptsPercentEncodedLocations(): void
    {
        $this->write('src/My Controller.php', "<?php\n");
        $result = [['uri' => 'file://'.$this->project.'/src/My%20Controller.php', 'range' => self::range(0, 0, 0, 5)]];

        self::assertSame([], $this->validate('textDocument/definition', $result));
    }

    public function testRejectsRangesBeyondTheLastLineOfTheTargetDocument(): void
    {
        $result = [['uri' => $this->uri('src/Controller.php'), 'range' => self::range(2, 0, 6, 0)]];

        self::assertSame(
            ['Range end 6:0 is outside "src/Controller.php", which has 6 line(s).'],
            $this->validate('textDocument/definition', $result),
        );
    }

    public function testRejectsRangesBeyondTheEndOfALine(): void
    {
        $result = [['uri' => $this->uri('src/Controller.php'), 'range' => self::range(2, 0, 2, 16)]];

        self::assertSame([], $this->validate('textDocument/definition', $result));

        $result = [['uri' => $this->uri('src/Controller.php'), 'range' => self::range(2, 0, 2, 17)]];

        self::assertSame(
            ['Range end 2:17 is outside "src/Controller.php", where line 2 is 16 UTF-16 code unit(s) long.'],
            $this->validate('textDocument/definition', $result),
        );
    }

    public function testCountsLineLengthsInUtf16CodeUnits(): void
    {
        $this->write('src/Unicode.php', "<?php // é 😀\n");
        $inBounds = [['uri' => $this->uri('src/Unicode.php'), 'range' => self::range(0, 0, 0, 13)]];
        $outOfBounds = [['uri' => $this->uri('src/Unicode.php'), 'range' => self::range(0, 0, 0, 14)]];

        self::assertSame([], $this->validate('textDocument/definition', $inBounds));
        self::assertCount(1, $this->validate('textDocument/definition', $outOfBounds));
    }

    public function testExcludesLineTerminatorsFromLineLengths(): void
    {
        $this->write('config/routes.yaml', "app_home:\r\n    path: /\r\n");
        $inBounds = [['uri' => $this->uri('config/routes.yaml'), 'range' => self::range(0, 0, 0, 8)]];
        $outOfBounds = [['uri' => $this->uri('config/routes.yaml'), 'range' => self::range(0, 0, 0, 10)]];

        self::assertSame([], $this->validate('textDocument/definition', $inBounds));
        self::assertCount(1, $this->validate('textDocument/definition', $outOfBounds));
    }

    public function testBoundsUnsavedRangesWithTheOpenTextInsteadOfTheSavedFile(): void
    {
        $result = [['uri' => $this->uri('src/Controller.php'), 'range' => self::range(6, 0, 6, 5)]];
        $openTexts = [$this->uri('src/Controller.php') => "<?php\n\nclass Controller\n{\n    public function index(): void\n    {\n    }\n}\n"];

        self::assertNotSame([], $this->validate('textDocument/definition', $result));
        self::assertSame([], $this->validate('textDocument/definition', $result, $openTexts));
    }

    public function testAcceptsLocationsThatOnlyExistAsOpenDocuments(): void
    {
        $result = [['uri' => $this->uri('src/Unsaved.php'), 'range' => self::range(0, 0, 0, 5)]];
        $openTexts = [$this->uri('src/Unsaved.php') => "<?php\n"];

        self::assertSame([], $this->validate('textDocument/definition', $result, $openTexts));
    }

    public function testBoundsRangesThatNameNoFileWithTheRequestedDocument(): void
    {
        $result = ['contents' => ['kind' => 'markdown', 'value' => 'Route'], 'range' => self::range(2, 0, 2, 20)];

        self::assertSame([], $this->validate('textDocument/hover', $result));
        self::assertSame(
            ['Range end 2:20 is outside "src/Controller.php", where line 2 is 16 UTF-16 code unit(s) long.'],
            $this->validate('textDocument/hover', $result, documentUri: $this->uri('src/Controller.php')),
        );
    }

    public function testBoundsDocumentLinkRangesWithTheRequestedDocumentAndNotWithTheirTarget(): void
    {
        $this->write('templates/base.html.twig', "\n");
        $result = [['range' => self::range(2, 0, 2, 16), 'target' => $this->uri('templates/base.html.twig')]];

        self::assertSame([], $this->validate('textDocument/documentLink', $result, documentUri: $this->uri('src/Controller.php')));
    }

    public function testAcceptsDocumentLinksPointingAtADirectory(): void
    {
        $result = [['range' => self::range(0, 0, 0, 5), 'target' => $this->uri('src')]];

        self::assertSame([], $this->validate('textDocument/documentLink', $result));
    }

    public function testAcceptsDocumentLinkFragments(): void
    {
        $result = [['range' => self::range(0, 0, 0, 5), 'target' => $this->uri('src/Controller.php').'#L3']];

        self::assertSame([], $this->validate('textDocument/documentLink', $result));
    }

    public function testIgnoresNonFileTargets(): void
    {
        $result = [['target' => 'https://symfony.com/doc/current/routing.html', 'range' => self::range(0, 0, 0, 1)]];

        self::assertSame([], $this->validate('textDocument/documentLink', $result));
    }

    public function testRejectsDocumentLinksPointingAtAMissingFile(): void
    {
        $result = [['range' => self::range(0, 0, 0, 5), 'target' => $this->uri('templates/missing.html.twig')]];

        self::assertSame(['Location "templates/missing.html.twig" does not exist.'], $this->validate('textDocument/documentLink', $result));
    }

    public function testBoundsLocationLinkRangesWithTheirTargetDocument(): void
    {
        $this->write('src/Service.php', "<?php\n");
        $result = [[
            'originSelectionRange' => self::range(2, 6, 2, 16),
            'targetUri' => $this->uri('src/Service.php'),
            'targetRange' => self::range(0, 0, 1, 0),
            'targetSelectionRange' => self::range(0, 0, 0, 9),
        ]];

        self::assertSame(
            ['Range end 0:9 is outside "src/Service.php", where line 0 is 5 UTF-16 code unit(s) long.'],
            $this->validate('textDocument/definition', $result, documentUri: $this->uri('src/Controller.php')),
        );
    }

    public function testBoundsLocationLinkOriginRangesWithTheRequestedDocument(): void
    {
        $this->write('src/Service.php', "<?php\n");
        $result = [[
            'originSelectionRange' => self::range(2, 6, 2, 40),
            'targetUri' => $this->uri('src/Service.php'),
            'targetRange' => self::range(0, 0, 0, 5),
            'targetSelectionRange' => self::range(0, 0, 0, 5),
        ]];

        self::assertSame(
            ['Range end 2:40 is outside "src/Controller.php", where line 2 is 16 UTF-16 code unit(s) long.'],
            $this->validate('textDocument/definition', $result, documentUri: $this->uri('src/Controller.php')),
        );
    }

    public function testValidatesLocationsCarriedByCodeLensCommands(): void
    {
        $result = [[
            'range' => self::range(2, 0, 2, 16),
            'command' => [
                'title' => '1 reference',
                'command' => 'editor.action.showReferences',
                'arguments' => [$this->uri('src/Controller.php'), ['line' => 2, 'character' => 0], [
                    ['uri' => $this->uri('src/Missing.php'), 'range' => self::range(0, 0, 0, 1)],
                ]],
            ],
        ]];

        self::assertSame(['Location "src/Missing.php" does not exist.'], $this->validate('textDocument/codeLens', $result));
    }

    public function testRejectsRenameEditsInDependencyOwnedFiles(): void
    {
        $this->write('templates/home.html.twig', "{{ 'home' }}\n");
        $this->write('vendor/acme/bundle/Resources/views/base.html.twig', "{{ 'base' }}\n");
        $result = ['changes' => [
            $this->uri('templates/home.html.twig') => [['range' => self::range(0, 0, 0, 4), 'newText' => 'renamed']],
            $this->uri('vendor/acme/bundle/Resources/views/base.html.twig') => [['range' => self::range(0, 0, 0, 4), 'newText' => 'renamed']],
        ]];

        $violations = $this->validate('textDocument/rename', $result);

        self::assertCount(1, $violations);
        self::assertStringContainsString('vendor/acme/bundle', $violations[0]);
        self::assertStringContainsString('dependency-owned or generated', $violations[0]);
    }

    public function testRejectsRenameDocumentChangesInGeneratedFiles(): void
    {
        $result = ['documentChanges' => [[
            'textDocument' => ['uri' => $this->uri('var/cache/dev/template.php'), 'version' => 1],
            'edits' => [['range' => self::range(0, 0, 0, 4), 'newText' => 'renamed']],
        ]]];

        $violations = $this->validate('textDocument/rename', $result);

        self::assertCount(1, $violations);
        self::assertStringContainsString('dependency-owned or generated', $violations[0]);
    }

    public function testRejectsRenameEditsOutsideTheApplication(): void
    {
        $result = ['changes' => [
            'file://'.$this->workspace->path('other/Controller.php') => [['range' => self::range(0, 0, 0, 4), 'newText' => 'renamed']],
        ]];

        $violations = $this->validate('textDocument/rename', $result);

        self::assertCount(1, $violations);
        self::assertStringContainsString('outside the application', $violations[0]);
    }

    public function testAcceptsRenameEditsInApplicationSources(): void
    {
        $result = ['changes' => [
            $this->uri('src/Controller.php') => [['range' => self::range(2, 6, 2, 16), 'newText' => 'Renamed']],
        ]];

        self::assertSame([], $this->validate('textDocument/rename', $result));
    }

    public function testRejectsRenameEditsOutsideTheEditedDocument(): void
    {
        $result = ['changes' => [
            $this->uri('src/Controller.php') => [['range' => self::range(2, 6, 2, 40), 'newText' => 'Renamed']],
        ]];

        self::assertSame(
            ['Range end 2:40 is outside "src/Controller.php", where line 2 is 16 UTF-16 code unit(s) long.'],
            $this->validate('textDocument/rename', $result),
        );
    }

    public function testAcceptsRenameEditsOnUnsavedDocuments(): void
    {
        $result = ['changes' => [
            $this->uri('src/Controller.php') => [['range' => self::range(6, 0, 6, 4), 'newText' => 'Renamed']],
        ]];
        $openTexts = [$this->uri('src/Controller.php') => "<?php\n\nclass Controller\n{\n    public function index(): void\n    {\n    }\n}\n"];

        self::assertNotSame([], $this->validate('textDocument/rename', $result));
        self::assertSame([], $this->validate('textDocument/rename', $result, $openTexts));
    }

    public function testRejectsCodeActionEditsInDependencyOwnedFiles(): void
    {
        $this->write('vendor/acme/bundle/translations/messages.en.yaml', "home: Home\n");
        $result = [[
            'title' => 'Add translation "home.title"',
            'kind' => 'quickfix',
            'edit' => ['documentChanges' => [[
                'textDocument' => ['uri' => $this->uri('vendor/acme/bundle/translations/messages.en.yaml'), 'version' => 1],
                'edits' => [['range' => self::range(1, 0, 1, 0), 'newText' => "home.title: Home\n"]],
            ]]],
        ]];

        $violations = $this->validate('textDocument/codeAction', $result);

        self::assertCount(1, $violations);
        self::assertStringContainsString('dependency-owned or generated', $violations[0]);
    }

    public function testRejectsCodeActionEditsOutsideTheEditedDocument(): void
    {
        $this->write('translations/messages.en.yaml', "home: Home\n");
        $result = [[
            'title' => 'Add translation "home.title"',
            'kind' => 'quickfix',
            'edit' => ['changes' => [
                $this->uri('translations/messages.en.yaml') => [['range' => self::range(3, 0, 3, 0), 'newText' => "home.title: Home\n"]],
            ]],
        ]];

        self::assertSame(
            ['Range start 3:0 is outside "translations/messages.en.yaml", which has 2 line(s).', 'Range end 3:0 is outside "translations/messages.en.yaml", which has 2 line(s).'],
            $this->validate('textDocument/codeAction', $result),
        );
    }

    public function testAcceptsCodeActionEditsInApplicationSources(): void
    {
        $this->write('translations/messages.en.yaml', "home: Home\n");
        $result = [[
            'title' => 'Add translation "home.title"',
            'kind' => 'quickfix',
            'diagnostics' => [['range' => self::range(2, 6, 2, 16), 'code' => 'translation.not_found']],
            'edit' => ['changes' => [
                $this->uri('translations/messages.en.yaml') => [['range' => self::range(1, 0, 1, 0), 'newText' => "home.title: Home\n"]],
            ]],
        ]];

        self::assertSame([], $this->validate('textDocument/codeAction', $result, documentUri: $this->uri('src/Controller.php')));
    }

    public function testChecksResourceOperationsWithoutRequiringTheirFilesToExist(): void
    {
        $accepted = ['documentChanges' => [['kind' => 'create', 'uri' => $this->uri('translations/messages.en.yaml')]]];
        $rejected = ['documentChanges' => [['kind' => 'rename', 'oldUri' => $this->uri('src/Controller.php'), 'newUri' => $this->uri('vendor/acme/Controller.php')]]];

        self::assertSame([], $this->validate('textDocument/rename', $accepted));
        $violations = $this->validate('textDocument/rename', $rejected);
        self::assertCount(1, $violations);
        self::assertStringContainsString('vendor/acme/Controller.php', $violations[0]);
    }

    public function testReportsEachDistinctViolationOnce(): void
    {
        $location = ['uri' => $this->uri('src/Missing.php'), 'range' => self::range(0, 0, 0, 1)];

        self::assertSame(['Location "src/Missing.php" does not exist.'], $this->validate('textDocument/references', [$location, $location]));
    }

    /**
     * @param array<array-key, mixed>|null $result
     * @param array<string, string>        $openTexts
     *
     * @return list<string>
     */
    private function validate(string $method, ?array $result, array $openTexts = [], ?string $documentUri = null): array
    {
        return (new ProtocolValidator())->validate($method, $result, $this->project, $openTexts, $documentUri);
    }

    private function write(string $relativePath, string $contents): void
    {
        $this->workspace->write('app/'.$relativePath, $contents);
    }

    private function uri(string $relativePath): string
    {
        return 'file://'.$this->project.'/'.$relativePath;
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
