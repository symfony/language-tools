<?php

namespace Symfony\Lsp\Tests\Check;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Check\CheckProfile;
use Symfony\Lsp\Check\CheckProfileProject;
use Symfony\Lsp\Check\CheckProfileReporter;
use Symfony\Lsp\Check\CheckProjectResult;
use Symfony\Lsp\Check\CheckResult;

final class CheckProfileReporterTest extends TestCase
{
    public function testRendersRuntimeProviderAndFileDetailsInTheirPhaseHierarchy(): void
    {
        $result = new CheckResult(
            '1.2.3',
            true,
            [new CheckProjectResult(
                '.',
                'dev',
                'runtime',
                null,
                ['state' => 'ready'],
                ['state' => 'ready', 'timings' => [
                    'totalMilliseconds' => 800.0,
                    'sectionsMilliseconds' => [
                        'container' => 300.0,
                        'routes' => 200.0,
                    ],
                ]],
                true,
            )],
            [],
            [],
            null,
            'none',
            false,
            [],
            0,
            new CheckProfile(
                1_100.0,
                [
                    'startup' => 100.0,
                    'configuration' => 10.0,
                    'projectDiscovery' => 20.0,
                    'fileSelection' => 30.0,
                    'projectAnalysis' => 850.0,
                    'diagnostics' => 50.0,
                    'resultProcessing' => 5.0,
                ],
                2.0,
                [new CheckProfileProject(
                    '.',
                    1,
                    [
                        'sourceIndex' => 25.0,
                        'filePreparation' => 5.0,
                        'runtimeIndex' => 820.0,
                        'diagnostics' => 50.0,
                    ],
                    ['route' => 40.0, 'template' => 10.0],
                    ['config/routes.yaml' => 45.0],
                )],
            ),
        );

        $output = (new CheckProfileReporter())->render($result);

        self::assertStringContainsString('Project . (1 file)', $output);
        self::assertStringContainsString('Application bridge', $output);
        self::assertStringContainsString('Runtime sections:', $output);
        self::assertStringContainsString('container', $output);
        self::assertStringContainsString('Diagnostic providers:', $output);
        self::assertStringContainsString('Slowest diagnostic files:', $output);
        self::assertStringContainsString('config/routes.yaml', $output);
        self::assertMatchesRegularExpression('/Runtime indexing.*Application bridge.*Diagnostics/s', $output);
    }
}
