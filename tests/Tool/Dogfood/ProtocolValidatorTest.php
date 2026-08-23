<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\Dogfood\ProtocolValidator;

final class ProtocolValidatorTest extends TestCase
{
    private const PROJECT = '/workspace/app';

    public function testAcceptsApplicationOwnedLocationsWithValidRanges(): void
    {
        $result = [[
            'uri' => 'file:///workspace/app/src/Controller.php',
            'range' => self::range(3, 4, 3, 10),
        ]];

        self::assertSame([], (new ProtocolValidator())->validate('textDocument/definition', $result, self::PROJECT));
    }

    public function testRejectsInvertedRanges(): void
    {
        $result = [['uri' => 'file:///workspace/app/src/Controller.php', 'range' => self::range(5, 2, 3, 0)]];

        $violations = (new ProtocolValidator())->validate('textDocument/definition', $result, self::PROJECT);

        self::assertCount(1, $violations);
        self::assertStringContainsString('Invalid range', $violations[0]);
    }

    public function testRejectsNegativePositions(): void
    {
        $result = ['range' => self::range(-1, 0, 0, 0)];

        self::assertCount(1, (new ProtocolValidator())->validate('textDocument/hover', $result, self::PROJECT));
    }

    public function testRejectsLocationsOutsideTheApplication(): void
    {
        $result = [['uri' => 'file:///workspace/other/src/Controller.php', 'range' => self::range(0, 0, 0, 1)]];

        $violations = (new ProtocolValidator())->validate('textDocument/references', $result, self::PROJECT);

        self::assertCount(1, $violations);
        self::assertStringContainsString('outside the application', $violations[0]);
    }

    public function testRejectsLexicallyContainedLocationsThatEscapeTheApplication(): void
    {
        $result = [['uri' => 'file:///workspace/app/../outside.php', 'range' => self::range(0, 0, 0, 1)]];

        $violations = (new ProtocolValidator())->validate('textDocument/definition', $result, self::PROJECT);

        self::assertCount(1, $violations);
        self::assertStringContainsString('outside the application', $violations[0]);
    }

    public function testIgnoresNonFileTargets(): void
    {
        $result = [['target' => 'https://symfony.com/doc/current/routing.html', 'range' => self::range(0, 0, 0, 1)]];

        self::assertSame([], (new ProtocolValidator())->validate('textDocument/documentLink', $result, self::PROJECT));
    }

    public function testRejectsRenameEditsInDependencyOwnedFiles(): void
    {
        $result = ['changes' => [
            'file:///workspace/app/templates/home.html.twig' => [['range' => self::range(0, 0, 0, 4), 'newText' => 'renamed']],
            'file:///workspace/app/vendor/acme/bundle/Resources/views/base.html.twig' => [['range' => self::range(0, 0, 0, 4), 'newText' => 'renamed']],
        ]];

        $violations = (new ProtocolValidator())->validate('textDocument/rename', $result, self::PROJECT);

        self::assertCount(1, $violations);
        self::assertStringContainsString('vendor/acme/bundle', $violations[0]);
    }

    public function testRejectsRenameDocumentChangesInGeneratedFiles(): void
    {
        $result = ['documentChanges' => [[
            'textDocument' => ['uri' => 'file:///workspace/app/var/cache/dev/template.php', 'version' => 1],
            'edits' => [['range' => self::range(0, 0, 0, 4), 'newText' => 'renamed']],
        ]]];

        $violations = (new ProtocolValidator())->validate('textDocument/rename', $result, self::PROJECT);

        self::assertCount(1, $violations);
        self::assertStringContainsString('dependency-owned or generated', $violations[0]);
    }

    public function testRejectsRenameEditsOutsideTheApplication(): void
    {
        $result = ['changes' => [
            'file:///workspace/other/Controller.php' => [['range' => self::range(0, 0, 0, 4), 'newText' => 'renamed']],
        ]];

        $violations = (new ProtocolValidator())->validate('textDocument/rename', $result, self::PROJECT);

        self::assertCount(1, $violations);
        self::assertStringContainsString('outside the application', $violations[0]);
    }

    public function testAcceptsRenameEditsInApplicationSources(): void
    {
        $result = ['changes' => [
            'file:///workspace/app/src/Controller.php' => [['range' => self::range(0, 0, 0, 4), 'newText' => 'renamed']],
        ]];

        self::assertSame([], (new ProtocolValidator())->validate('textDocument/rename', $result, self::PROJECT));
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
