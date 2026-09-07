<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\Dogfood\ScenarioStepException;
use Symfony\Lsp\Tools\Dogfood\Utf16Positions;
use Symfony\Lsp\Tools\Dogfood\WorkspaceEditApplier;

final class WorkspaceEditApplierTest extends TestCase
{
    private const ROOT = '/workspace/project';
    private const URI = 'file:///workspace/project/src/Controller.php';
    private const OTHER_URI = 'file:///workspace/project/config/routes.yaml';

    private WorkspaceEditApplier $applier;

    protected function setUp(): void
    {
        $this->applier = new WorkspaceEditApplier(new Utf16Positions());
    }

    public function testAppliesEveryEditOfEveryDocument(): void
    {
        $texts = $this->applier->apply([
            'documentChanges' => [
                [
                    'textDocument' => ['uri' => self::URI, 'version' => 2],
                    'edits' => [
                        $this->edit(0, 6, 0, 11, 'planet'),
                        $this->edit(0, 0, 0, 5, 'Howdy'),
                    ],
                ],
                [
                    'textDocument' => ['uri' => self::OTHER_URI, 'version' => 2],
                    'edits' => [$this->edit(1, 0, 1, 0, "extra: true\n")],
                ],
            ],
        ], [self::URI => "Hello world\n", self::OTHER_URI => "hello:\n"], self::ROOT);

        self::assertSame([self::URI => "Howdy planet\n", self::OTHER_URI => "hello:\nextra: true\n"], $texts);
    }

    public function testAppliesTheChangesForm(): void
    {
        $texts = $this->applier->apply(
            ['changes' => [self::URI => [$this->edit(0, 0, 0, 5, 'Howdy')]]],
            [self::URI => "Hello world\n"],
            self::ROOT,
        );

        self::assertSame([self::URI => "Howdy world\n"], $texts);
    }

    public function testCountsPositionsInUtf16CodeUnits(): void
    {
        $texts = $this->applier->apply(
            ['changes' => [self::URI => [$this->edit(0, 6, 0, 8, 'ok')]]],
            [self::URI => "héllo 🐘 world\n"],
            self::ROOT,
        );

        self::assertSame([self::URI => "héllo ok world\n"], $texts);
    }

    public function testRejectsOverlappingEdits(): void
    {
        $this->expectExceptionMessage('overlapping edits');
        $this->applier->apply([
            'changes' => [self::URI => [$this->edit(0, 0, 0, 6, 'Howdy '), $this->edit(0, 3, 0, 8, 'there')]],
        ], [self::URI => "Hello world\n"], self::ROOT);
    }

    public function testRejectsPositionsBeyondTheEndOfALine(): void
    {
        $this->expectExceptionMessage('Character 40 is beyond the 11 UTF-16 code units of line 0.');
        $this->applier->apply(
            ['changes' => [self::URI => [$this->edit(0, 0, 0, 40, 'Howdy')]]],
            [self::URI => "Hello world\n"],
            self::ROOT,
        );
    }

    public function testRejectsPositionsInsideASurrogatePair(): void
    {
        $this->expectExceptionMessage('splits a UTF-16 surrogate pair');
        $this->applier->apply(
            ['changes' => [self::URI => [$this->edit(0, 6, 0, 7, 'ok')]]],
            [self::URI => "héllo 🐘 world\n"],
            self::ROOT,
        );
    }

    public function testRejectsMissingLines(): void
    {
        $this->expectExceptionMessage('Line 9 is outside the 2 line document.');
        $this->applier->apply(
            ['changes' => [self::URI => [$this->edit(9, 0, 9, 0, 'Howdy')]]],
            [self::URI => "Hello world\n"],
            self::ROOT,
        );
    }

    public function testRejectsGeneratedAndDependencyOwnedTargets(): void
    {
        foreach (['vendor/acme/src/Acme.php', 'var/cache/app.php'] as $path) {
            $uri = 'file://'.self::ROOT.'/'.$path;
            try {
                $this->applier->apply(
                    ['changes' => [$uri => [$this->edit(0, 0, 0, 0, 'Howdy')]]],
                    [$uri => "Hello\n"],
                    self::ROOT,
                );
                self::fail(\sprintf('Applying an edit to "%s" should have been rejected.', $path));
            } catch (ScenarioStepException $exception) {
                self::assertStringContainsString('dependency-owned or generated', $exception->getMessage());
            }
        }
    }

    public function testRejectsTargetsOutsideTheApplication(): void
    {
        $this->expectExceptionMessage('outside the application');
        $this->applier->apply(
            ['changes' => ['file:///workspace/other/src/Acme.php' => [$this->edit(0, 0, 0, 0, 'Howdy')]]],
            ['file:///workspace/other/src/Acme.php' => "Hello\n"],
            self::ROOT,
        );
    }

    public function testRejectsDocumentsThatAreNotOpen(): void
    {
        $this->expectExceptionMessage('which is not open');
        $this->applier->apply(
            ['changes' => [self::URI => [$this->edit(0, 0, 0, 0, 'Howdy')]]],
            [self::OTHER_URI => "hello:\n"],
            self::ROOT,
        );
    }

    public function testRejectsResourceOperations(): void
    {
        $this->expectExceptionMessage('unsupported "create" resource operation');
        $this->applier->apply(
            ['documentChanges' => [['kind' => 'create', 'uri' => self::URI]]],
            [self::URI => "Hello world\n"],
            self::ROOT,
        );
    }

    public function testRejectsEditsWithoutAnyTextChange(): void
    {
        $this->expectExceptionMessage('does not declare "changes" or "documentChanges"');
        $this->applier->apply(['label' => 'Fix it'], [self::URI => "Hello\n"], self::ROOT);
    }

    public function testRejectsEditsDeclaringBothForms(): void
    {
        $this->expectExceptionMessage('declares both "changes" and "documentChanges"');
        $this->applier->apply(
            ['changes' => [self::URI => []], 'documentChanges' => []],
            [self::URI => "Hello\n"],
            self::ROOT,
        );
    }

    public function testRejectsMalformedTextEdits(): void
    {
        $this->expectExceptionMessage('malformed text edit');
        $this->applier->apply(
            ['changes' => [self::URI => [['newText' => 'Howdy']]]],
            [self::URI => "Hello\n"],
            self::ROOT,
        );
    }

    /**
     * @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string}
     */
    private function edit(int $startLine, int $startCharacter, int $endLine, int $endCharacter, string $newText): array
    {
        return [
            'range' => [
                'start' => ['line' => $startLine, 'character' => $startCharacter],
                'end' => ['line' => $endLine, 'character' => $endCharacter],
            ],
            'newText' => $newText,
        ];
    }
}
