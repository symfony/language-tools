<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\ExecutableRunner;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class DiagnosticBenchmarkTest extends TestCase
{
    public function testUsesExplicitDiagnosticCasesWithoutTheScalingCorpus(): void
    {
        $workspace = new TestWorkspace('symfony-lsp-diagnostic benchmark-');
        try {
            $workspace->mkdir('.git');
            $workspace->write('composer.json', '{"require":{"symfony/framework-bundle":"^8.1"}}');
            foreach (['First', 'Second'] as $name) {
                $workspace->write('src/Controller/'.$name.'Controller.php', <<<PHP
                    <?php
                    namespace App\Controller;

                    use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
                    use Symfony\Component\Routing\Attribute\Route;

                    final class {$name}Controller extends AbstractController {
                        #[Route('/{$name}', name: '{$name}')]
                        public function redirect(): void {
                            \$this->redirectToRoute('{$name}');
                        }
                    }
                    PHP);
            }
            $workspace->write('config/packages/extra.yaml', "parameters:\n    token: '%env(APP_URL%'\n");
            $root = \dirname(__DIR__, 2);
            $result = (new ExecutableRunner())->run([
                $root.'/tools/php-with-tree-sitter',
                $root.'/tools/benchmark-diagnostics.php',
                $workspace->rootPath,
            ], $root);

            self::assertSame(0, $result->exitCode, $result->stderr."\n".$result->stdout);
            /** @var array<string, mixed> $report */
            $report = json_decode($result->stdout, true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame(['route', 'template', 'twig_callable'], $report['fixtureProviders']);
            self::assertSame(4, $report['diagnostics']);
            self::assertSame([
                'templates/benchmark-callable.html.twig' => ['twig_callable.unknown_argument'],
                'templates/benchmark-route.html.twig' => ['route.not_found'],
                'templates/benchmark-template.html.twig' => ['template.not_found'],
            ], $report['fixtureDiagnostics']);
            self::assertSame(0, $report['phpParseCallsAfterIndexing']);
        } finally {
            $workspace->cleanup();
        }
    }
}
