<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionProjectLookup;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\Parameter;
use Symfony\Lsp\Feature\DependencyInjection\ParameterDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\ParameterIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpAutowireArgumentResolver;
use Symfony\Lsp\Feature\DependencyInjection\ProjectServiceSnapshotLoader;
use Symfony\Lsp\Feature\DependencyInjection\Service;
use Symfony\Lsp\Feature\DependencyInjection\ServiceCompletionHandler;
use Symfony\Lsp\Feature\DependencyInjection\ServiceDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\ServiceIndexRegistry;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlCommentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class ServiceCompletionHandlerTest extends TestCase
{
    public function testCompletesRuntimeServiceIdsWithoutExposingParameters(): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $text = <<<'YAML'
            services:
                App\Controller\DemoController:
                    arguments: ['@app.ma']
            YAML;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $indexes = new ServiceIndexRegistry();
        $parameterIndexes = new ParameterIndexRegistry();
        $sourceIndexes = new DependencyInjectionSourceIndexRegistry();
        (new ProjectServiceSnapshotLoader($indexes, $parameterIndexes))->load($project, [
            'complete' => true,
            'items' => [
                [
                    'id' => 'app.mailer',
                    'class' => 'App\\Mailer',
                    'tags' => ['kernel.reset'],
                ],
                [
                    'id' => 'app.storage',
                    'class' => 'App\\Storage',
                ],
            ],
            'parameters' => [
                ['name' => 'app.api_key', 'value' => 'CANARY_SECRET_VALUE'],
            ],
        ]);
        self::assertFalse($indexes->forProject($project)->isComplete());
        self::assertTrue($parameterIndexes->forProject($project)->isComplete());
        $converter = new PositionConverter();
        $cursor = strpos($text, 'app.ma') + \strlen('app.ma');
        $position = $converter->toPosition($text, $cursor);
        $handler = new ServiceCompletionHandler(
            new DocumentContextResolver($documents, $projects),
            $converter,
            new LspProtocolMapper(),
            new DependencyInjectionProjectLookup($indexes, $parameterIndexes, $sourceIndexes),
            $this->autowireArguments(),
            $this->yamlComments(),
        );

        $result = $handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]);

        self::assertSame([[
            'label' => 'app.mailer',
            'kind' => 18,
            'detail' => 'App\\Mailer',
            'textEdit' => [
                'range' => [
                    'start' => ['line' => 2, 'character' => 22],
                    'end' => ['line' => 2, 'character' => 28],
                ],
                'newText' => 'app.mailer',
            ],
        ]], $result);
        self::assertStringNotContainsString(
            'CANARY_SECRET_VALUE',
            json_encode($result, \JSON_THROW_ON_ERROR),
        );
    }

    public function testMergesRuntimeAndSourceCompletionsWithStableOrderingAndRuntimePrecedence(): void
    {
        $documents = new DocumentStore();
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $serviceIndexes = new ServiceIndexRegistry();
        $serviceIndexes->forProject($project)->replace(
            true,
            new Service('app.beta', 'App\\Beta', null, false, false, null, [], null, []),
            new Service('app.shared', 'App\\RuntimeShared', 'runtime.alias', false, false, null, [], null, []),
        );
        $parameterIndexes = new ParameterIndexRegistry();
        $parameterIndexes->forProject($project)->replace(
            true,
            new Parameter('app.beta', null),
            new Parameter('app.shared', 'Use app.replacement.'),
        );
        $sourceIndexes = new DependencyInjectionSourceIndexRegistry();
        $range = new Range(new Position(0, 0), new Position(0, 1));
        $sourceIndexes->forProject($project)->replace(new DependencyInjectionSourceFacts(
            'file:///workspace/config/source.yaml',
            [
                new ServiceDeclaration('app.alpha', 'file:///workspace/config/source.yaml', $range, 'App\\Alpha'),
                new ServiceDeclaration('app.shared', 'file:///workspace/config/source.yaml', $range, 'App\\SourceShared', 'source.alias'),
            ],
            [
                new ParameterDeclaration('app.alpha', 'file:///workspace/config/source.yaml', $range),
                new ParameterDeclaration('app.shared', 'file:///workspace/config/source.yaml', $range),
            ],
        ));
        $converter = new PositionConverter();
        $handler = new ServiceCompletionHandler(
            new DocumentContextResolver($documents, $projects),
            $converter,
            new LspProtocolMapper(),
            new DependencyInjectionProjectLookup($serviceIndexes, $parameterIndexes, $sourceIndexes),
            $this->autowireArguments(),
            $this->yamlComments(),
        );

        $serviceUri = 'file:///workspace/config/services.yaml';
        $serviceText = "arguments: ['@app.']";
        $documents->open(new Document($serviceUri, 'yaml', 1, $serviceText));
        $serviceResult = $handler->complete($this->params($serviceUri, $serviceText, $converter));

        self::assertSame(['app.alpha', 'app.beta', 'app.shared'], array_column($serviceResult ?? [], 'label'));
        self::assertSame(
            ['App\\Alpha', 'App\\Beta', 'Alias of runtime.alias'],
            array_column($serviceResult ?? [], 'detail'),
        );

        $parameterUri = 'file:///workspace/config/parameters.yaml';
        $parameterText = "arguments: ['%app.']";
        $documents->open(new Document($parameterUri, 'yaml', 1, $parameterText));
        $parameterResult = $handler->complete($this->params($parameterUri, $parameterText, $converter));

        self::assertSame(['app.alpha', 'app.beta', 'app.shared'], array_column($parameterResult ?? [], 'label'));
        self::assertSame(
            ['Symfony parameter', 'Symfony parameter', 'Deprecated Symfony parameter'],
            array_column($parameterResult ?? [], 'detail'),
        );
    }

    #[DataProvider('yamlCommentCompletionProvider')]
    public function testOffersNoCompletionsInsideYamlComments(string $text, string $prefix): void
    {
        $documents = new DocumentStore();
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $serviceIndexes = new ServiceIndexRegistry();
        $serviceIndexes->forProject($project)->replace(true, new Service(
            'app.mailer',
            'App\\Mailer',
            null,
            false,
            false,
            null,
            [],
            null,
            [],
        ));
        $parameterIndexes = new ParameterIndexRegistry();
        $parameterIndexes->forProject($project)->replace(true, new Parameter('app.api_key', null));
        $converter = new PositionConverter();
        $handler = new ServiceCompletionHandler(
            new DocumentContextResolver($documents, $projects),
            $converter,
            new LspProtocolMapper(),
            new DependencyInjectionProjectLookup(
                $serviceIndexes,
                $parameterIndexes,
                new DependencyInjectionSourceIndexRegistry(),
            ),
            $this->autowireArguments(),
            $this->yamlComments(),
        );
        $uri = 'file:///workspace/config/services.yaml';
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $position = $converter->toPosition($text, strpos($text, $prefix) + \strlen($prefix));

        self::assertNull($handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function yamlCommentCompletionProvider(): iterable
    {
        yield 'service' => ["# arguments: ['@app.ma']", 'app.ma'];
        yield 'parameter' => ["# arguments: ['%app.ap']", 'app.ap'];
    }

    public function testOffersNoParameterCompletionsInsidePhpComments(): void
    {
        $documents = new DocumentStore();
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $parameterIndexes = new ParameterIndexRegistry();
        $parameterIndexes->forProject($project)->replace(true, new Parameter('app.api_key', null));
        $converter = new PositionConverter();
        $handler = new ServiceCompletionHandler(
            new DocumentContextResolver($documents, $projects),
            $converter,
            new LspProtocolMapper(),
            new DependencyInjectionProjectLookup(
                new ServiceIndexRegistry(),
                $parameterIndexes,
                new DependencyInjectionSourceIndexRegistry(),
            ),
            $this->autowireArguments(),
            $this->yamlComments(),
        );
        $uri = 'file:///workspace/src/Service.php';
        $text = $this->php("// #[Autowire(param: 'app.a");
        $documents->open(new Document($uri, 'php', 1, $text));
        $position = $converter->toPosition($text, \strlen($text));

        self::assertNull($handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]));
    }

    public function testCompletesParametersInYamlAndPhpAttributes(): void
    {
        $documents = new DocumentStore();
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $serviceIndexes = new ServiceIndexRegistry();
        $serviceIndexes->forProject($project)->replace(true, new Service(
            'app.mailer',
            'App\\Mailer',
            null,
            false,
            false,
            null,
            [],
            null,
            [],
        ));
        $parameterIndexes = new ParameterIndexRegistry();
        $parameterIndexes->forProject($project)->replace(
            true,
            new Parameter('app.storage_dir', null),
            new Parameter('app.api_key', 'Use app.new_api_key.'),
        );
        $converter = new PositionConverter();
        $handler = new ServiceCompletionHandler(
            new DocumentContextResolver($documents, $projects),
            $converter,
            new LspProtocolMapper(),
            new DependencyInjectionProjectLookup(
                $serviceIndexes,
                $parameterIndexes,
                new DependencyInjectionSourceIndexRegistry(),
            ),
            $this->autowireArguments(),
            $this->yamlComments(),
        );

        $yamlUri = 'file:///workspace/config/services.yaml';
        $yaml = "arguments: ['%app.st']";
        $documents->open(new Document($yamlUri, 'yaml', 1, $yaml));
        $yamlPosition = $converter->toPosition($yaml, strpos($yaml, 'app.st') + \strlen('app.st'));
        $yamlResult = $handler->complete([
            'textDocument' => ['uri' => $yamlUri],
            'position' => ['line' => $yamlPosition->line, 'character' => $yamlPosition->character],
        ]);
        self::assertIsArray($yamlResult);
        self::assertIsArray($yamlResult[0]['textEdit']);

        self::assertSame('app.storage_dir', $yamlResult[0]['label'] ?? null);
        self::assertSame('app.storage_dir%', $yamlResult[0]['textEdit']['newText'] ?? null);

        $phpUri = 'file:///workspace/src/Service.php';
        $php = $this->php("#[Autowire(param: 'app.a')] final class Service {}");
        $documents->open(new Document($phpUri, 'php', 1, $php));
        $phpPosition = $converter->toPosition($php, strpos($php, 'app.a') + \strlen('app.a'));
        $phpResult = $handler->complete([
            'textDocument' => ['uri' => $phpUri],
            'position' => ['line' => $phpPosition->line, 'character' => $phpPosition->character],
        ]);
        self::assertIsArray($phpResult);
        self::assertIsArray($phpResult[0]['textEdit']);

        self::assertSame('app.api_key', $phpResult[0]['label'] ?? null);
        self::assertSame('app.api_key', $phpResult[0]['textEdit']['newText'] ?? null);

        $servicePhpUri = 'file:///workspace/src/MailerConsumer.php';
        $servicePhp = $this->php("#[Autowire(service: 'app.ma')] final class MailerConsumer {}");
        $documents->open(new Document($servicePhpUri, 'php', 1, $servicePhp));
        $servicePhpPosition = $converter->toPosition(
            $servicePhp,
            strpos($servicePhp, 'app.ma') + \strlen('app.ma'),
        );
        $servicePhpResult = $handler->complete([
            'textDocument' => ['uri' => $servicePhpUri],
            'position' => [
                'line' => $servicePhpPosition->line,
                'character' => $servicePhpPosition->character,
            ],
        ]);

        self::assertSame('app.mailer', $servicePhpResult[0]['label'] ?? null);
    }

    /**
     * @param list<string>|null $labels
     */
    #[DataProvider('phpAutowireCompletionProvider')]
    public function testBindsPhpCompletionToTheAutowireArgumentHoldingTheCursor(string $body, string $cursorAfter, ?array $labels): void
    {
        $documents = new DocumentStore();
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $serviceIndexes = new ServiceIndexRegistry();
        $serviceIndexes->forProject($project)->replace(true, new Service(
            'app.mailer',
            'App\\Mailer',
            null,
            false,
            false,
            null,
            [],
            null,
            [],
        ));
        $parameterIndexes = new ParameterIndexRegistry();
        $parameterIndexes->forProject($project)->replace(
            true,
            new Parameter('app.api_key', null),
            new Parameter('app.storage_dir', null),
        );
        $converter = new PositionConverter();
        $handler = new ServiceCompletionHandler(
            new DocumentContextResolver($documents, $projects),
            $converter,
            new LspProtocolMapper(),
            new DependencyInjectionProjectLookup(
                $serviceIndexes,
                $parameterIndexes,
                new DependencyInjectionSourceIndexRegistry(),
            ),
            $this->autowireArguments(),
            $this->yamlComments(),
        );
        $uri = 'file:///workspace/src/Service.php';
        $text = $this->php($body);
        $documents->open(new Document($uri, 'php', 1, $text));
        $cursor = strrpos($text, $cursorAfter);
        self::assertIsInt($cursor);
        $position = $converter->toPosition($text, $cursor + \strlen($cursorAfter));

        $result = $handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]);

        self::assertSame($labels, null === $result ? null : array_column($result, 'label'));
    }

    /** @return iterable<string, array{string, string, list<string>|null}> */
    public static function phpAutowireCompletionProvider(): iterable
    {
        yield 'incomplete service argument' => ["final class S {\n    #[Autowire(service: 'app.ma\n", 'app.ma', ['app.mailer']];
        yield 'incomplete optional service argument' => ["#[Autowire(service: '?app.ma", 'app.ma', ['app.mailer']];
        yield 'incomplete param argument' => ["#[Autowire(param: 'app.api", 'app.api', ['app.api_key']];
        yield 'incomplete parameter placeholder' => ["#[Autowire('%app.st", 'app.st', ['app.storage_dir']];
        yield 'incomplete argument of a grouped autowire' => ["#[Other(service: 'x'), Autowire(service: 'app.ma", 'app.ma', ['app.mailer']];
        yield 'incomplete argument of a fully qualified autowire' => [
            "#[\\Symfony\\Component\\DependencyInjection\\Attribute\\Autowire(service: 'app.ma",
            'app.ma',
            ['app.mailer'],
        ];
        yield 'service argument of a later unrelated attribute' => [
            "#[Autowire(service: 'app.mailer')]\n#[Other(service: 'app.ma",
            'app.ma',
            null,
        ];
        yield 'param argument of a later unrelated attribute' => [
            "#[Autowire(param: 'app.api_key')]\n#[Other(param: 'app.api",
            'app.api',
            null,
        ];
        yield 'placeholder of a later unrelated attribute' => [
            "#[Autowire('%app.storage_dir%')]\n#[Other('%app.st",
            'app.st',
            null,
        ];
        yield 'named argument of a later function call' => [
            "#[Autowire(service: 'app.mailer')]\nfinal class S { public function f() { helper(service: 'app.ma",
            'app.ma',
            null,
        ];
        yield 'attribute of another namespace' => ["#[\\App\\Autowire(service: 'app.ma", 'app.ma', null];
        yield 'cursor after a closed service argument' => ["#[Autowire(service: 'app.mailer')]", "'app.mailer'", null];
    }

    private function php(string $body): string
    {
        return "<?php\n\nuse Symfony\\Component\\DependencyInjection\\Attribute\\Autowire;\n\n".$body;
    }

    private function autowireArguments(): PhpAutowireArgumentResolver
    {
        return new PhpAutowireArgumentResolver(new TolerantPhpParser(new Parser()));
    }

    private function yamlComments(): YamlCommentParser
    {
        return new YamlCommentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()));
    }

    /** @return array<string, mixed> */
    private function params(string $uri, string $text, PositionConverter $converter): array
    {
        $position = $converter->toPosition($text, \strlen($text) - 2);

        return [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ];
    }
}
