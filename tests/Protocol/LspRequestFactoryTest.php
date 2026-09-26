<?php

namespace Symfony\Lsp\Tests\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspRequestFactory;

final class LspRequestFactoryTest extends TestCase
{
    private Document $document;
    private DocumentStore $documents;
    private Project $project;
    private LspRequestFactory $factory;

    protected function setUp(): void
    {
        $this->document = new Document('file:///workspace/config/services.yaml', 'yaml', 1, "services:\n  app.mailer: ~\n");
        $this->documents = new DocumentStore();
        $this->documents->open($this->document);
        $this->project = new Project('/workspace', 'file:///workspace');
        $projects = new ProjectRegistry();
        $projects->replace([$this->project]);
        $this->factory = new LspRequestFactory($this->documents, $projects, new PositionConverter());
    }

    public function testBuildsADocumentRequest(): void
    {
        $request = $this->factory->document(['textDocument' => ['uri' => $this->document->uri]]);

        self::assertNotNull($request);
        self::assertSame($this->document, $request->document);
        self::assertSame($this->project, $request->project);
        self::assertSame($this->document->uri, $request->source->uri);
    }

    public function testBuildsAPositionedRequest(): void
    {
        $request = $this->factory->positioned([
            'textDocument' => ['uri' => $this->document->uri],
            'position' => ['line' => 1, 'character' => 3],
        ]);

        self::assertNotNull($request);
        self::assertSame($this->document, $request->document);
        self::assertSame($this->project, $request->project);
        self::assertSame(1, $request->position->line);
        self::assertSame(3, $request->position->character);
        self::assertSame(13, $request->offset);
    }

    /** @param array<array-key, mixed> $params */
    #[DataProvider('invalidDocumentParamsProvider')]
    public function testRejectsInvalidDocuments(array $params, ?string $uri): void
    {
        $positioned = $params + ['position' => ['line' => 0, 'character' => 0]];

        self::assertSame($uri, $this->factory->uri($params));
        self::assertNull($this->factory->document($params));
        self::assertNull($this->factory->positioned($positioned));
        self::assertNull($this->factory->references($positioned));
        self::assertNull($this->factory->rename($positioned + ['newName' => 'renamed']));
        self::assertNull($this->factory->codeAction($params + ['context' => ['diagnostics' => []]]));
    }

    /** @return iterable<string, array{array<array-key, mixed>, string|null}> */
    public static function invalidDocumentParamsProvider(): iterable
    {
        yield 'missing text document' => [[], null];
        yield 'invalid text document' => [['textDocument' => 'invalid'], null];
        yield 'missing URI' => [['textDocument' => []], null];
        yield 'invalid URI' => [['textDocument' => ['uri' => 1]], null];
        yield 'unknown document' => [['textDocument' => ['uri' => 'file:///workspace/config/unknown.yaml']], 'file:///workspace/config/unknown.yaml'];
    }

    public function testRejectsADocumentOutsideAProject(): void
    {
        $document = new Document('file:///outside/config/services.yaml', 'yaml', 1, 'services: {}');
        $this->documents->open($document);
        $params = ['textDocument' => ['uri' => $document->uri]];
        $positioned = $params + ['position' => ['line' => 0, 'character' => 0]];

        self::assertNull($this->factory->forUri($document->uri));
        self::assertNull($this->factory->document($params));
        self::assertNull($this->factory->positioned($positioned));
        self::assertNull($this->factory->references($positioned));
        self::assertNull($this->factory->rename($positioned + ['newName' => 'renamed']));
        self::assertNull($this->factory->codeAction($params + ['context' => ['diagnostics' => []]]));
    }

    /** @param array<array-key, mixed>|string $position */
    #[DataProvider('invalidPositionProvider')]
    public function testRejectsInvalidPositions(array|string $position): void
    {
        $params = [
            'textDocument' => ['uri' => $this->document->uri],
            'position' => $position,
        ];

        self::assertNotNull($this->factory->document($params));
        self::assertNull($this->factory->positioned($params));
        self::assertNull($this->factory->references($params));
        self::assertNull($this->factory->rename($params + ['newName' => 'renamed']));
    }

    /** @return iterable<string, array{array<array-key, mixed>|string}> */
    public static function invalidPositionProvider(): iterable
    {
        yield 'invalid position' => ['0:0'];
        yield 'missing line' => [['character' => 0]];
        yield 'missing character' => [['line' => 0]];
        yield 'invalid line' => [['line' => '0', 'character' => 0]];
        yield 'invalid character' => [['line' => 0, 'character' => '0']];
        yield 'negative line' => [['line' => -1, 'character' => 0]];
        yield 'negative character' => [['line' => 0, 'character' => -1]];
    }

    public function testRejectsAMissingPosition(): void
    {
        $params = ['textDocument' => ['uri' => $this->document->uri], 'newName' => 'renamed'];

        self::assertNotNull($this->factory->document($params));
        self::assertNull($this->factory->positioned($params));
        self::assertNull($this->factory->references($params));
        self::assertNull($this->factory->rename($params));
    }

    /** @param array<array-key, mixed> $params */
    #[DataProvider('includeDeclarationProvider')]
    public function testReportsDeclarationsUnlessTheClientOptsOut(array $params, bool $includeDeclaration): void
    {
        $request = $this->factory->references($this->position() + $params);

        self::assertNotNull($request);
        self::assertSame($includeDeclaration, $request->includeDeclaration);
        self::assertSame(1, $request->position->line);
    }

    /** @return iterable<string, array{array<array-key, mixed>, bool}> */
    public static function includeDeclarationProvider(): iterable
    {
        yield 'missing context' => [[], true];
        yield 'invalid context' => [['context' => 'invalid'], true];
        yield 'omitted' => [['context' => []], true];
        yield 'false' => [['context' => ['includeDeclaration' => false]], false];
        yield 'true' => [['context' => ['includeDeclaration' => true]], true];
    }

    public function testBuildsARenameRequest(): void
    {
        $request = $this->factory->rename($this->position() + ['newName' => 'app.notifier']);

        self::assertNotNull($request);
        self::assertSame('app.notifier', $request->newName);
        self::assertSame(1, $request->position->line);
        self::assertSame(3, $request->position->character);
    }

    #[DataProvider('invalidNewNameProvider')]
    public function testRejectsAnInvalidNewName(mixed $newName): void
    {
        $params = $this->position();
        if (null !== $newName) {
            $params['newName'] = $newName;
        }

        self::assertNull($this->factory->rename($params));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidNewNameProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'not a string' => [42];
    }

    /** @param array<array-key, mixed> $params */
    #[DataProvider('invalidCodeActionContextProvider')]
    public function testRejectsACodeActionWithoutAContext(array $params): void
    {
        self::assertNull($this->factory->codeAction(['textDocument' => ['uri' => $this->document->uri]] + $params));
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidCodeActionContextProvider(): iterable
    {
        yield 'missing context' => [[]];
        yield 'invalid context' => [['context' => 'invalid']];
    }

    public function testKeepsOnlyWellFormedCodeActionDiagnostics(): void
    {
        $range = ['start' => ['line' => 1, 'character' => 2], 'end' => ['line' => 1, 'character' => 12]];
        $diagnostic = ['range' => $range, 'code' => 'service.not_found', 'message' => 'Unknown service.'];

        $request = $this->factory->codeAction([
            'textDocument' => ['uri' => $this->document->uri],
            'context' => ['diagnostics' => [
                'not a diagnostic',
                ['range' => $range, 'message' => 'Without a code.'],
                ['range' => $range, 'code' => 42],
                ['code' => 'service.not_found'],
                ['range' => 'invalid', 'code' => 'service.not_found'],
                ['range' => ['start' => $range['start']], 'code' => 'service.not_found'],
                ['range' => ['start' => ['line' => -1, 'character' => 0], 'end' => $range['end']], 'code' => 'service.not_found'],
                ['range' => ['start' => $range['start'], 'end' => ['line' => 1, 'character' => '12']], 'code' => 'service.not_found'],
                $diagnostic,
            ]],
        ]);

        self::assertNotNull($request);
        $diagnostics = $request->diagnostics();
        self::assertCount(1, $diagnostics);
        self::assertSame('service.not_found', $diagnostics[0]->code);
        self::assertSame($diagnostic, $diagnostics[0]->diagnostic);
        self::assertTrue($diagnostics[0]->range->equals(new Range(new Position(1, 2), new Position(1, 12))));
    }

    /** @param array<array-key, mixed> $context */
    #[DataProvider('diagnosticFreeContextProvider')]
    public function testBuildsACodeActionRequestWithoutDiagnostics(array $context): void
    {
        $request = $this->factory->codeAction(['textDocument' => ['uri' => $this->document->uri], 'context' => $context]);

        self::assertNotNull($request);
        self::assertSame($this->document, $request->document);
        self::assertSame([], $request->diagnostics());
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function diagnosticFreeContextProvider(): iterable
    {
        yield 'missing diagnostics' => [[]];
        yield 'invalid diagnostics' => [['diagnostics' => 'invalid']];
        yield 'no diagnostics' => [['diagnostics' => []]];
    }

    /** @return array<array-key, mixed> */
    private function position(): array
    {
        return [
            'textDocument' => ['uri' => $this->document->uri],
            'position' => ['line' => 1, 'character' => 3],
        ];
    }
}
