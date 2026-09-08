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
     * @param list<string> $expected
     */
    #[DataProvider('sourceOnlyClassificationProvider')]
    public function testClassifiesSourceOnlyRuns(HarnessResult $run, array $expected): void
    {
        self::assertSame($expected, (new RunClassifier())->classify($run, 'source-only'));
    }

    /**
     * @return iterable<string, array{HarnessResult, list<string>}>
     */
    public static function sourceOnlyClassificationProvider(): iterable
    {
        yield 'success' => [self::sourceOnlyResult(), []];
        yield 'enabled runtime reported disabled' => [self::sourceOnlyResult(['status' => array_replace(self::indexStatus('ready', 'disabled'), ['runtimeEnabled' => true])]), ['analysis-mode']];
        yield 'missing enabled state' => [self::sourceOnlyResult(['status' => ['source' => ['state' => 'ready'], 'runtime' => ['state' => 'disabled']]]), ['analysis-mode']];
        yield 'implicit runtime report' => [self::harnessResult(['status' => self::indexStatus('ready', 'disabled')]), ['analysis-mode']];
        yield 'runtime report' => [self::harnessResult(), ['analysis-mode']];
        yield 'runtime booted anyway' => [self::sourceOnlyResult(['status' => self::indexStatus('ready', 'ready')]), ['analysis-mode']];
        yield 'runtime attempted and failed' => [self::sourceOnlyResult(['status' => self::indexStatus('ready', 'failed')]), ['analysis-mode']];
        yield 'runtime left unindexed' => [self::sourceOnlyResult(['status' => self::indexStatus('ready', 'not-indexed')]), ['analysis-mode']];
        yield 'missing status' => [self::sourceOnlyResult(['status' => null]), ['timeout', 'analysis-mode']];
        yield 'source index failed' => [self::sourceOnlyResult(['status' => self::indexStatus('failed', 'disabled')]), ['source-index']];
        yield 'scenario failure' => [
            self::sourceOnlyResult(['scenarios' => [['id' => 'route.twig', 'status' => 'error', 'checks' => []]]]),
            ['scenario'],
        ];
        yield 'reported mode is not trusted twice' => [
            self::sourceOnlyResult(['analysisMode' => 'runtime', 'status' => self::indexStatus('ready', 'ready')]),
            ['analysis-mode'],
        ];
    }

    /**
     * @return iterable<string, array{HarnessResult, list<string>}>
     */
    public static function classificationProvider(): iterable
    {
        yield 'success' => [self::harnessResult(), []];
        yield 'explicit null mode' => [self::harnessResult(['analysisMode' => null]), ['analysis-mode']];
        yield 'timeout' => [new HarnessResult(-1, true, null, '', ''), ['timeout']];
        yield 'invalid output' => [new HarnessResult(0, false, null, 'not json', ''), ['process']];
        yield 'harness crash' => [new HarnessResult(1, false, self::decodedResult(), '{}', 'boom'), ['process']];
        yield 'server exit code' => [self::harnessResult(['exitCode' => 3]), ['process']];
        yield 'server error output' => [self::harnessResult(['serverError' => 'warning']), ['process']];
        yield 'source index failed' => [self::harnessResult(['status' => self::indexStatus('failed', 'ready')]), ['source-index']];
        yield 'runtime index failed' => [self::harnessResult(['status' => self::indexStatus('ready', 'failed')]), ['runtime-index']];
        yield 'runtime index stale' => [self::harnessResult(['status' => self::indexStatus('ready', 'stale')]), ['runtime-index']];
        yield 'runtime index partial' => [self::harnessResult(['status' => self::indexStatus('ready', 'partial')]), ['runtime-index']];
        yield 'runtime index disabled' => [self::harnessResult(['status' => self::indexStatus('ready', 'disabled')]), ['runtime-index']];
        yield 'source-only report' => [self::sourceOnlyResult(), ['analysis-mode', 'runtime-index']];
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
        yield 'no scenarios' => [self::harnessResult(['scenarios' => [], 'scenarioCount' => 0]), ['scenario']];
        yield 'missing scenario count' => [self::harnessResult(['scenarioCount' => null]), ['scenario']];
        yield 'wrong scenario count' => [self::harnessResult(['scenarioCount' => 2]), ['scenario']];
        yield 'assertion failure' => [self::harnessResult(['assertionFailures' => 1]), ['scenario']];
        yield 'missing assertions' => [self::harnessResult(['scenarios' => [['id' => 'route.twig', 'status' => 'pass', 'checks' => []]]]), ['scenario']];
        yield 'request error' => [self::harnessResult(['scenarios' => [['id' => 'route.twig', 'status' => 'error', 'checks' => []]]]), ['scenario']];
        yield 'combined failure' => [
            self::harnessResult([
                'status' => self::indexStatus('failed', 'failed'),
                'scenarios' => [['id' => 'route.twig', 'status' => 'error', 'checks' => []]],
                'serverError' => 'boom',
            ]),
            ['source-index', 'runtime-index', 'scenario', 'process'],
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
     */
    private static function sourceOnlyResult(array $overrides = []): HarnessResult
    {
        return self::harnessResult(array_merge([
            'analysisMode' => 'source-only',
            'status' => self::indexStatus('ready', 'disabled'),
        ], $overrides));
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
            'scenarioCount' => 1,
            'assertionFailures' => 0,
            'scenarios' => [['id' => 'route.twig', 'status' => 'pass', 'checks' => [[
                'phase' => 'baseline', 'method' => 'hover', 'status' => 'pass', 'fingerprint' => str_repeat('a', 64), 'failures' => [],
            ]], 'failures' => []]],
            'violations' => [],
            'serverError' => null,
            'exitCode' => 0,
        ], $overrides);
    }

    /**
     * @return array{source: array{state: string}, runtime: array{state: string}, runtimeEnabled: bool}
     */
    private static function indexStatus(string $source, string $runtime): array
    {
        return ['source' => ['state' => $source], 'runtime' => ['state' => $runtime], 'runtimeEnabled' => 'disabled' !== $runtime];
    }
}
