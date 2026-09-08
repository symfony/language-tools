<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\TestWorkspace;
use Symfony\Lsp\Tools\Dogfood\DiagnosticCheckHarness;
use Symfony\Lsp\Tools\Dogfood\NativeProcessRunner;
use Symfony\Lsp\Tools\Dogfood\ProjectConfiguration;
use Symfony\Lsp\Tools\Dogfood\RunClassifier;
use Symfony\Lsp\Tools\Dogfood\ServerHarness;

final class SourceOnlyExecutableTest extends TestCase
{
    public function testSourceOnlyScenariosAndDiagnosticsNeverLoadTheApplicationAutoloader(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('The source executable integration requires Unix executable scripts.');
        }

        $workspace = new TestWorkspace('dogfood-source-only-');
        try {
            $workspace->write('composer.json', json_encode(['type' => 'project', 'require' => ['symfony/framework-bundle' => '^7.4']], \JSON_THROW_ON_ERROR));
            $workspace->write('.symfony-lsp.json', '{"version":1,"releaseMetadata":false}');
            $workspace->write('templates/index.html.twig', "{{ 'catalog.greeting'|trans }}\n");
            $workspace->write('translations/messages.en.yaml', "catalog.greeting: Hello\n");
            $marker = $workspace->path('application-autoloaded');
            $workspace->write('vendor/autoload.php', '<?php file_put_contents('.var_export($marker, true).', "loaded"); throw new RuntimeException("Application autoload must not run");');
            $manifest = $workspace->write('scenario.json', json_encode([
                'version' => 1,
                'revision' => str_repeat('a', 40),
                'scenarios' => [[
                    'id' => 'translation.twig',
                    'file' => 'templates/index.html.twig',
                    'anchor' => 'catalog.greeting',
                    'offset' => 5,
                    'expect' => ['completion' => ['includes' => ['catalog.greeting']]],
                    'edit' => [
                        'before' => 'catalog.greeting',
                        'after' => 'catalog.greet',
                        'anchor' => 'catalog.greet',
                        'offset' => 13,
                        'expect' => ['completion' => ['includes' => ['catalog.greeting']]],
                    ],
                ]],
                'diagnostics' => [],
            ], \JSON_THROW_ON_ERROR));
            $processes = new NativeProcessRunner();
            $root = \dirname(__DIR__, 3);
            $harness = new ServerHarness($processes, $root.'/tools/dogfood-server', $root.'/bin/symfony-lsp');
            $sourceOnly = new ProjectConfiguration('application', 'https://example.com/application.git', str_repeat('a', 40), null, 'dev', 'composer-no-scripts', false, 20, scenarioFile: $manifest, analysisMode: 'source-only');
            $run = $harness->run($sourceOnly, $workspace->path());

            self::assertSame([], (new RunClassifier())->classify($run, 'source-only'), $run->rawOutput.$run->errorOutput);
            self::assertSame('source-only', $run->result['analysisMode'] ?? null);
            self::assertFileDoesNotExist($marker);

            $check = (new DiagnosticCheckHarness($processes, $root.'/bin/symfony-lsp'))->run($sourceOnly, $workspace->path());
            self::assertTrue($check->ok(), $check->failure ?? '');
            self::assertSame('source-only', $check->analysisMode);
            self::assertSame([], $check->diagnostics);
            self::assertSame(5, $check->analyzedFiles);
            self::assertFileDoesNotExist($marker);

            $runtime = new ProjectConfiguration('application', 'https://example.com/application.git', str_repeat('a', 40), null, 'dev', 'composer', false, 20, scenarioFile: $manifest);
            $failed = $harness->run($runtime, $workspace->path());
            self::assertNotSame([], (new RunClassifier())->classify($failed));
            self::assertFileExists($marker);
        } finally {
            $workspace->cleanup();
        }
    }
}
