<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\Dogfood\HarnessResult;
use Symfony\Lsp\Tools\Dogfood\RunClassifier;

final class RunClassifierTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('classificationProvider')]
    public function testClassifiesRuns(HarnessResult $run, array $expected): void
    {
        self::assertSame($expected, (new RunClassifier())->classify($run));
    }

    /**
     * @return iterable<string, array{HarnessResult, list<string>}>
     */
    public static function classificationProvider(): iterable
    {
        yield 'success' => [self::harnessResult(), []];
        yield 'timeout' => [new HarnessResult(-1, true, null, '', ''), ['timeout']];
        yield 'invalid output' => [new HarnessResult(0, false, null, 'not json', ''), ['process']];
        yield 'harness crash' => [new HarnessResult(1, false, self::decodedResult(), '{}', 'boom'), ['process']];
        yield 'server exit code' => [self::harnessResult(['exitCode' => 3]), ['process']];
        yield 'server error output' => [self::harnessResult(['serverError' => 'warning']), ['process']];
        yield 'source index failed' => [self::harnessResult(['status' => self::indexStatus('failed', 'ready')]), ['source-index']];
        yield 'runtime index failed' => [self::harnessResult(['status' => self::indexStatus('ready', 'failed')]), ['runtime-index']];
        yield 'runtime index stale' => [self::harnessResult(['status' => self::indexStatus('ready', 'stale')]), ['runtime-index']];
        yield 'bootstrap failed' => [
            self::harnessResult(['status' => ['source' => ['state' => 'ready'], 'runtime' => ['state' => 'failed', 'stage' => 'bootstrap']]]),
            ['bootstrap'],
        ];
        yield 'missing status' => [self::harnessResult(['status' => null]), ['timeout']];
        yield 'nonterminal indexes' => [self::harnessResult(['status' => self::indexStatus('indexing', 'indexing')]), ['timeout']];
        yield 'protocol violation' => [
            self::harnessResult(['violations' => [['category' => 'route.twig', 'method' => 'rename', 'message' => 'Rename edits "vendor/a.twig".']]]),
            ['request'],
        ];
        yield 'request error' => [
            self::harnessResult(['probes' => [['requests' => ['hover' => ['error' => 'Internal error.']]]]]),
            ['request'],
        ];
        yield 'combined failure' => [
            self::harnessResult([
                'status' => self::indexStatus('failed', 'failed'),
                'probes' => [['requests' => ['hover' => ['error' => 'Internal error.']]]],
                'serverError' => 'boom',
            ]),
            ['source-index', 'runtime-index', 'request', 'process'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function harnessResult(array $overrides = []): HarnessResult
    {
        return new HarnessResult(0, false, self::decodedResult($overrides), '{}', '');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function decodedResult(array $overrides = []): array
    {
        return array_merge([
            'status' => self::indexStatus('ready', 'ready'),
            'terminal' => true,
            'probes' => [['requests' => ['hover' => ['error' => null]]]],
            'violations' => [],
            'serverError' => null,
            'exitCode' => 0,
        ], $overrides);
    }

    /**
     * @return array{source: array{state: string}, runtime: array{state: string}}
     */
    private static function indexStatus(string $source, string $runtime): array
    {
        return ['source' => ['state' => $source], 'runtime' => ['state' => $runtime]];
    }
}
