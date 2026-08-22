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

    public function testReturnsNullWithoutProbes(): void
    {
        $scorer = new SupportScorer();

        self::assertNull($scorer->score([]));
        self::assertNull($scorer->score(['probes' => []]));
        self::assertNull($scorer->score(['probes' => ['not a probe']]));
    }
}
