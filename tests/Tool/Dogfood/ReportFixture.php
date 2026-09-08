<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use Symfony\Lsp\Tests\Support\TestWorkspace;
use Symfony\Lsp\Tools\Dogfood\ReportHistory;

/** @phpstan-import-type HistoryEntry from ReportHistory */
final class ReportFixture
{
    /** @return array<string, mixed> */
    public static function project(string $analysisMode = 'runtime'): array
    {
        $phase = ['scenarios' => 1, 'checks' => 1, 'requests' => 1, 'failures' => 0, 'violations' => 0, 'layers' => [], 'source' => 'ready', 'runtime' => 'runtime' === $analysisMode ? 'ready' : 'disabled', 'serverVersion' => 'dev', 'timings' => ['scenariosMilliseconds' => 12.0]];

        return [
            'name' => 'app',
            'analysisMode' => $analysisMode,
            'revision' => str_repeat('a', 40),
            'environment' => 'dev',
            'frameworkBundle' => '8.1.0',
            'expectationFingerprint' => str_repeat('b', 64),
            'dependencies' => ['composerLockSha256' => str_repeat('c', 64)],
            'ok' => true,
            'failure' => null,
            'cold' => $phase,
            'warm' => $phase,
            'diagnostics' => ['ok' => true, 'analysisMode' => $analysisMode, 'analyzedFiles' => 123, 'diagnostics' => []],
            'knownGaps' => [],
            'timings' => ['totalMilliseconds' => 1234.0],
        ];
    }

    /** @return array<string, mixed> */
    public static function phase(string $status = 'pass', string $analysisMode = 'runtime'): array
    {
        return ['analysisMode' => $analysisMode, 'status' => ['source' => ['state' => 'ready'], 'runtime' => ['state' => 'runtime' === $analysisMode ? 'ready' : 'disabled'], 'runtimeEnabled' => 'runtime' === $analysisMode], 'scenarioCount' => 1, 'requestCount' => 1, 'scenarios' => [[
            'id' => 'route.twig', 'status' => $status,
            'checks' => [['phase' => 'baseline', 'method' => 'completion', 'status' => $status, 'fingerprint' => str_repeat('d', 64)]],
        ]]];
    }

    /** @param array<string, mixed>|null $project */
    public static function write(TestWorkspace $workspace, string $run = '20260907-120000', ?array $project = null, string $status = 'pass'): void
    {
        $project ??= self::project();
        $workspace->write($run.'/app/project.json', json_encode($project, \JSON_THROW_ON_ERROR));
        foreach (['cold', 'warm'] as $phase) {
            $workspace->write($run.'/app/'.$phase.'.json', json_encode(self::phase($status, \is_string($project['analysisMode'] ?? null) ? $project['analysisMode'] : 'runtime'), \JSON_THROW_ON_ERROR));
        }
    }

    /** @return HistoryEntry */
    public static function entry(string $run = '20260907-120000'): array
    {
        $history = new ReportHistory();

        return $history->validate([
            'version' => 2, 'analysisMode' => 'runtime', 'run' => $run, 'project' => 'app', 'time' => $history->time($run), 'outcome' => 'passed', 'finalized' => true, 'layers' => [],
            'revision' => str_repeat('a', 40), 'dependencies' => str_repeat('c', 64), 'environment' => 'dev', 'framework' => '8.1.0', 'serverVersion' => 'dev',
            'expectations' => str_repeat('b', 64), 'checkSet' => str_repeat('d', 64), 'comparison' => $history->comparison(str_repeat('a', 40), str_repeat('c', 64), 'dev', str_repeat('b', 64)),
            'scenarios' => 1, 'checks' => 2, 'passed' => 2, 'failed' => 0, 'errors' => 0, 'requests' => 2, 'knownGaps' => 0, 'diagnostics' => 0, 'files' => 123, 'milliseconds' => 1234.0, 'scenarioMilliseconds' => 24.0,
        ]);
    }
}
