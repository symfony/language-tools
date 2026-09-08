<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\Dogfood\ScenarioEvidence;
use Symfony\Lsp\Tools\Dogfood\ScenarioManifest;

final class ScenarioEvidenceTest extends TestCase
{
    public function testEveryEditingPhaseMustVerifyItsDeclaredMethods(): void
    {
        $manifest = new ScenarioManifest(str_repeat('a', 40), [[
            'id' => 'route.edit', 'file' => 'templates/page.html.twig', 'anchor' => 'home', 'offset' => 0,
            'expect' => ['completion' => ['includes' => ['home']]],
            'edit' => [
                'file' => 'templates/page.html.twig', 'before' => 'home', 'after' => 'hom',
                'expect' => ['completion' => ['includes' => ['home']]],
                'afterFix' => ['diagnostics' => ['equals' => []]],
            ],
        ]], []);
        $checks = [];
        foreach (['baseline' => 'completion', 'edit' => 'completion', 'afterFix' => 'diagnostics', 'restored' => 'completion'] as $phase => $method) {
            $checks[] = ['phase' => $phase, 'method' => $method];
        }
        $evidence = new ScenarioEvidence();
        self::assertTrue($evidence->covers($manifest, ['scenarios' => [['id' => 'route.edit', 'checks' => $checks]]]));
        foreach (array_keys($checks) as $missing) {
            self::assertFalse($evidence->covers($manifest, ['scenarios' => [[
                'id' => 'route.edit', 'checks' => array_filter($checks, static fn (int $key): bool => $missing !== $key, \ARRAY_FILTER_USE_KEY),
            ]]]));
        }
    }

    public function testDuplicateChecksCannotSubstituteForAnotherMethod(): void
    {
        $manifest = new ScenarioManifest(str_repeat('a', 40), [[
            'id' => 'route.twig', 'file' => 'templates/page.html.twig', 'anchor' => 'home', 'offset' => 0,
            'expect' => ['hover' => ['includes' => ['home']]],
        ]], []);
        $check = ['phase' => 'baseline', 'method' => 'hover'];

        self::assertFalse((new ScenarioEvidence())->covers($manifest, ['scenarios' => [['id' => 'route.twig', 'checks' => [$check, $check]]]]));
    }

    public function testSemanticParityDoesNotCompareTimingsButRetainsEveryResponseHash(): void
    {
        $first = ['id' => 'route.twig', 'checks' => [['phase' => 'baseline', 'method' => 'hover', 'fingerprint' => str_repeat('a', 64), 'milliseconds' => 1.0]]];
        $second = ['id' => 'route.php', 'checks' => [['phase' => 'baseline', 'method' => 'definition', 'fingerprint' => str_repeat('b', 64)]]];
        $evidence = new ScenarioEvidence();
        $expected = $evidence->semantics(['scenarios' => [$first, $second]]);
        $first['checks'][0]['milliseconds'] = 123.0;
        self::assertSame($expected, $evidence->semantics(['scenarios' => [$second, $first]]));
        $first['checks'][0]['fingerprint'] = str_repeat('c', 64);
        self::assertNotSame($expected, $evidence->semantics(['scenarios' => [$second, $first]]));
    }

    public function testDiagnosticDifferencesDescribeWordingChangesAndPreserveMultiplicity(): void
    {
        $first = ['path' => 'config/services.yaml', 'code' => 'service.not_found', 'severity' => 'error', 'range' => [
            'start' => ['line' => 0, 'character' => 1], 'end' => ['line' => 0, 'character' => 5],
        ], 'messageHash' => str_repeat('a', 64)];
        $second = array_replace($first, ['messageHash' => str_repeat('b', 64)]);
        $evidence = new ScenarioEvidence();

        $difference = $evidence->diagnosticDifference([$first, $first], [$second]);
        self::assertStringContainsString('1 added, 2 removed', $difference);
        self::assertStringContainsString(str_repeat('a', 64), $difference);
        self::assertStringContainsString(str_repeat('b', 64), $difference);
    }

    public function testDiagnosticReviewNotesDoNotHideChangesOrDuplicates(): void
    {
        $diagnostic = ['path' => 'templates/page.html.twig', 'code' => 'route.not_found', 'severity' => 'error', 'range' => [
            'start' => ['line' => 0, 'character' => 4], 'end' => ['line' => 0, 'character' => 8],
        ]];
        $reviewed = $diagnostic + ['kind' => 'known-gap', 'reason' => 'A custom router supplies this name outside the inspected collection.'];
        $evidence = new ScenarioEvidence();

        self::assertSame($evidence->diagnostics([$diagnostic]), $evidence->diagnostics([array_reverse($reviewed, true)]));
        self::assertNotSame($evidence->diagnostics([$diagnostic]), $evidence->diagnostics([$reviewed, $reviewed]));
        $reviewed['range']['start']['character'] = 5;
        self::assertNotSame($evidence->diagnostics([$diagnostic]), $evidence->diagnostics([$reviewed]));
    }
}
