<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\Dogfood\SupportScorer;

final class SupportScorerTest extends TestCase
{
    public function testScoresExpectedRequestKindsPerCategory(): void
    {
        $score = (new SupportScorer())->score(['probes' => [
            [
                'category' => 'route.php',
                'file' => 'src/Controller/PostController.php',
                'value' => 'post_show',
                'requests' => [
                    'completion' => ['resultCount' => 3, 'error' => null],
                    'hover' => ['resultCount' => 1, 'error' => null],
                    'definition' => ['resultCount' => 1, 'error' => null],
                    'references' => ['resultCount' => 2, 'error' => null],
                ],
            ],
            [
                'category' => 'route.php',
                'file' => 'src/Controller/HomeController.php',
                'value' => 'home',
                'requests' => [
                    'completion' => ['resultCount' => 1, 'error' => null],
                    'hover' => ['resultCount' => 0, 'error' => null],
                    'definition' => ['resultCount' => 1, 'error' => 'boom'],
                    'references' => ['resultCount' => 0, 'error' => null],
                ],
            ],
            [
                'category' => 'form.option.php',
                'file' => 'src/Form/PostType.php',
                'value' => 'label',
                'requests' => [
                    'completion' => ['resultCount' => 1, 'error' => null],
                    'hover' => ['resultCount' => 1, 'error' => null],
                    'definition' => ['resultCount' => 0, 'error' => null],
                    'references' => ['resultCount' => 0, 'error' => null],
                ],
            ],
        ]]);

        self::assertNotNull($score);
        // route.php averages a full and a quarter probe; form options only expect completion and hover
        self::assertEquals(['form.option.php' => 1.0, 'route.php' => 0.625], $score['categories']);
        self::assertSame(0.8125, $score['score']);
        self::assertSame(3, $score['probeCount']);
        self::assertSame(12, \strlen($score['fingerprint']));
    }

    public function testFingerprintTracksTheProbeSetNotTheResults(): void
    {
        $scorer = new SupportScorer();
        $probe = static fn (int $results): array => [
            'category' => 'route.php',
            'file' => 'src/A.php',
            'value' => 'home',
            'requests' => ['completion' => ['resultCount' => $results, 'error' => null]],
        ];

        $empty = $scorer->score(['probes' => [$probe(0)]]);
        $full = $scorer->score(['probes' => [$probe(5)]]);
        self::assertNotNull($empty);
        self::assertNotNull($full);
        self::assertSame($empty['fingerprint'], $full['fingerprint']);
        self::assertNotSame($empty['score'], $full['score']);
    }

    public function testExclusionsRemoveImpossibleKindsFromTheDenominator(): void
    {
        $report = ['probes' => [
            [
                'category' => 'asset.twig',
                'file' => 'templates/base.html.twig',
                'value' => 'build/app.css',
                'requests' => [
                    'completion' => ['resultCount' => 2, 'error' => null],
                    'hover' => ['resultCount' => 0, 'error' => null],
                    'definition' => ['resultCount' => 0, 'error' => null],
                    'references' => ['resultCount' => 1, 'error' => null],
                ],
            ],
        ]];
        $exclusions = [[
            'project' => 'sulu-demo',
            'category' => 'asset.twig',
            'value' => 'build/app.css',
            'kinds' => ['hover', 'definition'],
        ]];

        $unscoped = (new SupportScorer($exclusions))->score($report, 'other-project');
        $scoped = (new SupportScorer($exclusions))->score($report, 'sulu-demo');
        self::assertNotNull($unscoped);
        self::assertNotNull($scoped);
        self::assertSame(0.5, $unscoped['score']);
        self::assertEquals(1.0, $scoped['score']);
        self::assertSame($unscoped['fingerprint'], $scoped['fingerprint']);
    }

    public function testWildcardExclusionsApplyToEveryProject(): void
    {
        $report = ['probes' => [[
            'category' => 'parameter.yaml',
            'file' => 'config/packages/assets.yaml',
            'value' => 'kernel.project_dir',
            'requests' => [
                'completion' => ['resultCount' => 1, 'error' => null],
                'hover' => ['resultCount' => 1, 'error' => null],
                'definition' => ['resultCount' => 0, 'error' => null],
                'references' => ['resultCount' => 3, 'error' => null],
            ],
        ]]];
        $scorer = new SupportScorer([['project' => '*', 'category' => 'parameter.yaml', 'value' => 'kernel.project_dir', 'kinds' => ['definition']]]);

        self::assertEquals(1.0, $scorer->score($report, 'any-project')['score'] ?? null);
    }

    public function testFullyExcludedProbesLeaveTheCategory(): void
    {
        $probe = [
            'category' => 'asset.twig',
            'file' => 'templates/base.html.twig',
            'value' => 'build/app.css',
            'requests' => ['completion' => ['resultCount' => 0, 'error' => null]],
        ];
        $scorer = new SupportScorer([['project' => 'demo', 'category' => 'asset.twig', 'value' => 'build/app.css']]);

        self::assertNull($scorer->score(['probes' => [$probe]], 'demo'));
    }

    public function testReturnsNullWithoutProbes(): void
    {
        $scorer = new SupportScorer();

        self::assertNull($scorer->score([]));
        self::assertNull($scorer->score(['probes' => []]));
        self::assertNull($scorer->score(['probes' => ['not a probe']]));
    }
}
