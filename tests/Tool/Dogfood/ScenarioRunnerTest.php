<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\ContentLengthProcessClient;
use Symfony\Lsp\Tools\Dogfood\ProtocolValidator;
use Symfony\Lsp\Tools\Dogfood\ResponseAssertions;
use Symfony\Lsp\Tools\Dogfood\ResponseFingerprint;
use Symfony\Lsp\Tools\Dogfood\ScenarioLocator;
use Symfony\Lsp\Tools\Dogfood\ScenarioManifest;
use Symfony\Lsp\Tools\Dogfood\ScenarioManifestLoader;
use Symfony\Lsp\Tools\Dogfood\ScenarioRunner;
use Symfony\Lsp\Tools\Dogfood\Utf16Positions;
use Symfony\Lsp\Tools\Dogfood\WorkspaceEditApplier;

/**
 * @phpstan-import-type ScenarioCheckReport from ScenarioRunner
 * @phpstan-import-type ScenarioRunReport from ScenarioRunner
 */
final class ScenarioRunnerTest extends TestCase
{
    private const CONTROLLER = <<<'PHP'
        <?php

        namespace App\Controller;

        final class HelloController
        {
            public function index(): string
            {
                return $this->render('hello/index.html.twig');
            }
        }

        PHP;

    private string $directory;
    private string $project;
    private ?ContentLengthProcessClient $client = null;

    protected function setUp(): void
    {
        $this->directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-scenario-runner-'.bin2hex(random_bytes(8)));
        $this->project = Path::join($this->directory, 'project');
        $filesystem = new Filesystem();
        $filesystem->dumpFile(Path::join($this->project, 'src/Controller/HelloController.php'), self::CONTROLLER);
        $filesystem->dumpFile(Path::join($this->project, 'translations/messages.en.xlf'), <<<'XML'
            <?xml version="1.0"?>
            <xliff version="1.2">
                <file source-language="en" datatype="plaintext">
                    <body>
                        <trans-unit id="greeting">
                            <source>greeting</source>
                            <target>Hello</target>
                        </trans-unit>
                    </body>
                </file>
            </xliff>

            XML);
    }

    protected function tearDown(): void
    {
        $this->client?->terminate();
        $this->client = null;
        (new Filesystem())->remove($this->directory);
    }

    public function testReportsWrongButNonEmptyResultsAsFailures(): void
    {
        $server = new ScriptedLanguageServer($this->directory, ['responses' => [
            ['method' => 'textDocument/completion', 'result' => [['label' => 'other/template.html.twig']]],
        ]]);
        $report = $this->execute($server, [$this->scenario([
            'expect' => ['completion' => ['equals' => ['hello/index.html.twig']]],
        ])]);

        self::assertSame(1, $report['scenarioCount']);
        self::assertSame('fail', $report['scenarios'][0]['status']);
        $check = $this->check($report, 'hello.completion', 'baseline', 'completion');
        self::assertSame('fail', $check['status']);
        self::assertNotEmpty($check['failures']);
        self::assertSame(\count($check['failures']), $report['assertionFailures']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $check['fingerprint']);
        self::assertSame([], $report['violations']);
    }

    public function testReportsAbsentAnchorsWithoutOpeningTheDocument(): void
    {
        $server = new ScriptedLanguageServer($this->directory, []);
        $report = $this->execute($server, [$this->scenario([
            'anchor' => 'this anchor drifted away',
            'offset' => 0,
            'expect' => ['completion' => ['includes' => ['hello/index.html.twig']], 'hover' => ['includes' => ['Hello']]],
        ])]);

        $scenario = $report['scenarios'][0];
        self::assertSame('error', $scenario['status']);
        self::assertStringContainsString('no longer appears', $scenario['failures'][0]);
        self::assertSame(['baseline|completion', 'baseline|hover'], array_map(
            static fn (array $check): string => $check['phase'].'|'.$check['method'],
            $scenario['checks'],
        ));
        foreach ($scenario['checks'] as $check) {
            self::assertSame('error', $check['status']);
            self::assertNull($check['fingerprint']);
            self::assertStringContainsString('no longer appears', $check['failures'][0]);
        }
        self::assertSame(0, $report['requestCount']);
        self::assertNotContains('textDocument/didOpen', $server->methods());
    }

    public function testSendsOnlyTheExpectedMethods(): void
    {
        $server = new ScriptedLanguageServer($this->directory, ['responses' => [
            ['method' => 'textDocument/completion', 'result' => [['label' => 'hello/index.html.twig']]],
            ['method' => 'textDocument/hover', 'result' => ['contents' => ['kind' => 'markdown', 'value' => 'The hello template.']]],
        ]]);
        $report = $this->execute($server, [$this->scenario([
            'expect' => [
                'completion' => ['equals' => ['hello/index.html.twig']],
                'hover' => ['includes' => ['hello template']],
            ],
        ])]);

        self::assertSame('pass', $report['scenarios'][0]['status']);
        self::assertSame(0, $report['assertionFailures']);
        self::assertSame([
            'textDocument/didOpen',
            'textDocument/completion',
            'textDocument/hover',
            'textDocument/didClose',
        ], $server->methods());
        self::assertSame(2, $report['requestCount']);
    }

    public function testFailsWhenDiagnosticsAreNotPublishedForTheCurrentVersion(): void
    {
        $server = new ScriptedLanguageServer($this->directory, [
            'publishVersion' => 99,
            'diagnostics' => [['contains' => 'hello/index.html.twig', 'items' => [['code' => 'twig.missing_template', 'find' => 'hello/index.html.twig']]]],
        ]);
        $report = $this->execute($server, [$this->scenario([
            'expect' => ['diagnostics' => ['equals' => []]],
        ])]);

        $check = $this->check($report, 'hello.completion', 'baseline', 'diagnostics');
        self::assertSame('error', $check['status']);
        self::assertStringContainsString('no diagnostics for version 1', $check['failures'][0]);
        self::assertSame('error', $report['scenarios'][0]['status']);
        self::assertGreaterThan(0, $report['requestCount']);
    }

    public function testAssertsTheDiagnosticsOfTheEditedVersion(): void
    {
        $server = new ScriptedLanguageServer($this->directory, [
            'diagnostics' => [['contains' => 'hello/missing.html.twig', 'items' => [['code' => 'twig.missing_template', 'find' => 'hello/missing.html.twig']]]],
        ]);
        $report = $this->execute($server, [$this->scenario([
            'expect' => ['diagnostics' => ['equals' => []]],
            'edit' => [
                'before' => 'hello/index.html.twig',
                'after' => 'hello/missing.html.twig',
                'anchor' => '$this->render(',
                'offset' => 14,
                'expect' => ['diagnostics' => ['equals' => []]],
            ],
        ])]);

        self::assertSame('pass', $this->check($report, 'hello.completion', 'baseline', 'diagnostics')['status']);
        self::assertSame('fail', $this->check($report, 'hello.completion', 'edit', 'diagnostics')['status']);
        self::assertSame('pass', $this->check($report, 'hello.completion', 'restored', 'diagnostics')['status']);
        self::assertSame('fail', $report['scenarios'][0]['status']);
        self::assertContains('workspace/executeCommand', $server->methods());
    }

    public function testAppliesACodeActionAndRollsTheDocumentBack(): void
    {
        $server = new ScriptedLanguageServer($this->directory, [
            'diagnostics' => [['contains' => 'hello/missing.html.twig', 'items' => [['code' => 'twig.missing_template', 'find' => 'hello/missing.html.twig']]]],
            'responses' => [[
                'method' => 'textDocument/codeAction',
                'requiresDiagnostic' => 'twig.missing_template',
                'actions' => [['title' => 'Create the missing template', 'replace' => ['find' => 'hello/missing.html.twig', 'newText' => 'hello/fixed.html.twig']]],
            ]],
        ]);
        $report = $this->execute($server, [$this->scenario([
            'expect' => ['diagnostics' => ['equals' => []]],
            'edit' => [
                'before' => 'hello/index.html.twig',
                'after' => 'hello/missing.html.twig',
                'anchor' => '$this->render(',
                'offset' => 14,
                'expect' => ['diagnostics' => ['includes' => ['twig.missing_template:error:8:30-8:53']]],
                'applyCodeAction' => 'Create the missing template',
                'afterFix' => ['diagnostics' => ['equals' => []]],
            ],
        ])]);

        self::assertSame([], $report['scenarios'][0]['failures']);
        self::assertSame('pass', $report['scenarios'][0]['status']);
        self::assertSame(0, $report['assertionFailures']);
        $context = $server->codeActionContexts()[0];
        self::assertSame('twig.missing_template', $context['diagnostics'][0]['code'] ?? null);
        self::assertSame($context['diagnostics'][0]['range'] ?? null, $context['range']);
        $texts = $server->changedTexts();
        self::assertCount(3, $texts);
        self::assertStringContainsString("'hello/missing.html.twig'", $texts[0]);
        self::assertStringContainsString("'hello/fixed.html.twig'", $texts[1]);
        self::assertSame(self::CONTROLLER, $texts[2]);
        self::assertContains('textDocument/didClose', $server->methods());
        self::assertSame(self::CONTROLLER, file_get_contents(Path::join($this->project, 'src/Controller/HelloController.php')));
    }

    public function testRestoresTheDocumentWhenTheCodeActionCannotBeApplied(): void
    {
        $server = new ScriptedLanguageServer($this->directory, [
            'rootUri' => 'file://'.str_replace('%2F', '/', rawurlencode((string) realpath($this->project))),
            'diagnostics' => [['contains' => 'hello/missing.html.twig', 'items' => [['code' => 'twig.missing_template', 'find' => 'hello/missing.html.twig']]]],
            'responses' => [[
                'method' => 'textDocument/codeAction',
                'requiresDiagnostic' => 'twig.missing_template',
                'actions' => [[
                    'title' => 'Create the missing template',
                    'edit' => ['documentChanges' => [[
                        'textDocument' => ['uri' => '{rootUri}/vendor/acme/src/Controller.php', 'version' => 2],
                        'edits' => [['range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]], 'newText' => 'Hello']],
                    ]]],
                ]],
            ]],
        ]);
        $report = $this->execute($server, [$this->scenario([
            'expect' => ['diagnostics' => ['equals' => []]],
            'edit' => [
                'before' => 'hello/index.html.twig',
                'after' => 'hello/missing.html.twig',
                'anchor' => '$this->render(',
                'offset' => 14,
                'expect' => ['diagnostics' => ['includes' => ['twig.missing_template:error:8:30-8:53']]],
                'applyCodeAction' => 'Create the missing template',
                'afterFix' => ['diagnostics' => ['equals' => []]],
            ],
        ])]);

        $scenario = $report['scenarios'][0];
        self::assertSame('error', $scenario['status']);
        self::assertStringContainsString('dependency-owned or generated', $scenario['failures'][0]);
        self::assertSame('error', $this->check($report, 'hello.completion', 'afterFix', 'diagnostics')['status']);
        self::assertSame('pass', $this->check($report, 'hello.completion', 'restored', 'diagnostics')['status']);
        $texts = $server->changedTexts();
        self::assertSame(self::CONTROLLER, end($texts));
        self::assertContains('textDocument/didClose', $server->methods());
    }

    public function testOpensTranslationsWithTheXmlLanguageId(): void
    {
        $server = new ScriptedLanguageServer($this->directory, ['responses' => [
            ['method' => 'textDocument/definition', 'result' => []],
        ]]);
        $report = $this->execute($server, [[
            'id' => 'translation.definition',
            'file' => 'translations/messages.en.xlf',
            'anchor' => '<source>greeting</source>',
            'offset' => 8,
            'expect' => ['definition' => ['equals' => []]],
        ]]);

        self::assertSame('pass', $report['scenarios'][0]['status']);
        self::assertSame(['xml'], $server->openedLanguageIds());
    }

    public function testReportsEveryScenarioAfterTheServerStops(): void
    {
        $server = new ScriptedLanguageServer($this->directory, ['responses' => [
            ['method' => 'textDocument/completion', 'result' => [['label' => 'hello/index.html.twig']]],
            ['method' => 'textDocument/hover', 'exit' => true],
        ]]);
        $report = $this->execute($server, [
            $this->scenario(['id' => 'first.completion', 'expect' => ['completion' => ['equals' => ['hello/index.html.twig']]]]),
            $this->scenario(['id' => 'second.hover', 'expect' => ['hover' => ['includes' => ['Hello']]]]),
            $this->scenario(['id' => 'third.completion', 'expect' => ['completion' => ['equals' => ['hello/index.html.twig']]]]),
        ]);

        self::assertSame(3, $report['scenarioCount']);
        self::assertSame(['first.completion', 'second.hover', 'third.completion'], array_column($report['scenarios'], 'id'));
        self::assertSame(['pass', 'error', 'error'], array_column($report['scenarios'], 'status'));
        self::assertNotNull($report['transportFailure']);
        self::assertStringContainsString('broke the server connection', $report['scenarios'][1]['failures'][0]);
        self::assertStringContainsString('The server is unavailable', $report['scenarios'][2]['failures'][0]);
        foreach (\array_slice($report['scenarios'], 1) as $scenario) {
            self::assertCount(1, $scenario['checks']);
            self::assertSame('error', $scenario['checks'][0]['status']);
            self::assertNull($scenario['checks'][0]['fingerprint']);
        }
    }

    public function testReportsRequestErrorsWithoutStoppingTheRun(): void
    {
        $server = new ScriptedLanguageServer($this->directory, ['responses' => [
            ['method' => 'textDocument/completion', 'error' => ['code' => -32603, 'message' => 'Completion exploded.']],
            ['method' => 'textDocument/hover', 'result' => ['contents' => ['kind' => 'markdown', 'value' => 'Hello template.']]],
        ]]);
        $report = $this->execute($server, [$this->scenario([
            'expect' => [
                'completion' => ['equals' => ['hello/index.html.twig']],
                'hover' => ['includes' => ['Hello template']],
            ],
        ])]);

        self::assertSame('error', $report['scenarios'][0]['status']);
        self::assertSame([], $report['scenarios'][0]['failures']);
        self::assertStringContainsString('Completion exploded.', $this->check($report, 'hello.completion', 'baseline', 'completion')['failures'][0]);
        self::assertSame('pass', $this->check($report, 'hello.completion', 'baseline', 'hover')['status']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function scenario(array $overrides): array
    {
        return $overrides + [
            'id' => 'hello.completion',
            'file' => 'src/Controller/HelloController.php',
            'anchor' => "'hello/index.html.twig'",
            'offset' => 1,
        ];
    }

    /**
     * @param list<array<string, mixed>> $scenarios
     *
     * @return ScenarioRunReport
     */
    private function execute(ScriptedLanguageServer $server, array $scenarios): array
    {
        $this->client = new ContentLengthProcessClient([$server->path], 5.0, getenv());
        $runner = new ScenarioRunner(
            $this->client,
            new ScenarioLocator(),
            new ResponseAssertions(),
            new ProtocolValidator(),
            new WorkspaceEditApplier(new Utf16Positions()),
            new ResponseFingerprint(),
            5.0,
        );

        return $runner->run($this->manifest($scenarios), $this->project);
    }

    /**
     * @param list<array<string, mixed>> $scenarios
     */
    private function manifest(array $scenarios): ScenarioManifest
    {
        $path = Path::join($this->directory, 'scenarios.json');
        (new Filesystem())->dumpFile($path, json_encode([
            'version' => 1,
            'revision' => str_repeat('a', 40),
            'scenarios' => $scenarios,
            'diagnostics' => [],
        ], \JSON_THROW_ON_ERROR));

        return (new ScenarioManifestLoader())->load($path);
    }

    /**
     * @param ScenarioRunReport $report
     *
     * @return ScenarioCheckReport
     */
    private function check(array $report, string $scenarioId, string $phase, string $method): array
    {
        foreach ($report['scenarios'] as $scenario) {
            if ($scenarioId !== $scenario['id']) {
                continue;
            }
            foreach ($scenario['checks'] as $check) {
                if ($phase === $check['phase'] && $method === $check['method']) {
                    return $check;
                }
            }
        }

        self::fail(\sprintf('The report has no "%s" check of "%s" in the "%s" phase.', $method, $scenarioId, $phase));
    }
}
