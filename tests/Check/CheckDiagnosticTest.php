<?php

namespace Symfony\Lsp\Tests\Check;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Check\CheckDiagnostic;
use Symfony\Lsp\Check\CheckFile;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Project\Project;

final class CheckDiagnosticTest extends TestCase
{
    /**
     * @param array{start: array{line: int, character: int}, end: array{line: int, character: int}} $range
     */
    #[DataProvider('invalidRangeProvider')]
    public function testRejectsInvalidRanges(array $range): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $file = new CheckFile(
            $project,
            '/workspace/config/services.yaml',
            'config/services.yaml',
            'config/services.yaml',
            'file:///workspace/config/services.yaml',
            'yaml',
            false,
        );
        $diagnostic = [
            'range' => $range,
            'severity' => 1,
            'code' => 'service.not_found',
            'source' => 'symfony',
            'message' => 'Service does not exist.',
        ];

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('A diagnostic provider returned an invalid diagnostic.');

        CheckDiagnostic::fromProtocol($file, '.', "services:\n", $diagnostic, new PositionConverter());
    }

    /**
     * @return iterable<string, array{array{start: array{line: int, character: int}, end: array{line: int, character: int}}}>
     */
    public static function invalidRangeProvider(): iterable
    {
        yield 'negative start line' => [[
            'start' => ['line' => -1, 'character' => 0],
            'end' => ['line' => 0, 'character' => 0],
        ]];
        yield 'negative start character' => [[
            'start' => ['line' => 0, 'character' => -1],
            'end' => ['line' => 0, 'character' => 0],
        ]];
        yield 'negative end line' => [[
            'start' => ['line' => 0, 'character' => 0],
            'end' => ['line' => -1, 'character' => 0],
        ]];
        yield 'negative end character' => [[
            'start' => ['line' => 0, 'character' => 0],
            'end' => ['line' => 0, 'character' => -1],
        ]];
        yield 'end line before start line' => [[
            'start' => ['line' => 1, 'character' => 0],
            'end' => ['line' => 0, 'character' => 0],
        ]];
        yield 'end character before start character' => [[
            'start' => ['line' => 0, 'character' => 1],
            'end' => ['line' => 0, 'character' => 0],
        ]];
    }
}
