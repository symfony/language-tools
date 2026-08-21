<?php

namespace Symfony\Lsp\Tests\Document;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContext;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionedDocumentContext;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class DocumentContextResolverTest extends TestCase
{
    private Document $document;
    private DocumentStore $documents;
    private Project $project;
    private ProjectRegistry $projects;
    private DocumentContextResolver $resolver;

    protected function setUp(): void
    {
        $this->document = new Document('file:///workspace/config/services.yaml', 'yaml', 1, 'services: {}');
        $this->documents = new DocumentStore();
        $this->documents->open($this->document);
        $this->project = new Project('/workspace', 'file:///workspace', '^8.0');
        $this->projects = new ProjectRegistry();
        $this->projects->replace([$this->project]);
        $this->resolver = new DocumentContextResolver($this->documents, $this->projects);
    }

    public function testResolvesDocumentContextWithoutAPosition(): void
    {
        $context = $this->resolver->resolveDocument([
            'textDocument' => ['uri' => $this->document->uri()],
        ]);

        self::assertInstanceOf(DocumentContext::class, $context);
        self::assertSame($this->document, $context->document);
        self::assertSame($this->project, $context->project);
    }

    public function testResolvesPositionedDocumentContext(): void
    {
        $context = $this->resolver->resolvePositioned([
            'textDocument' => ['uri' => $this->document->uri()],
            'position' => ['line' => 2, 'character' => 3],
        ]);

        self::assertInstanceOf(PositionedDocumentContext::class, $context);
        self::assertSame($this->document, $context->document);
        self::assertSame($this->project, $context->project);
        self::assertSame(2, $context->position->line());
        self::assertSame(3, $context->position->character());
    }

    /** @param array<array-key, mixed> $params */
    #[DataProvider('invalidDocumentParamsProvider')]
    public function testRejectsInvalidDocumentContexts(array $params): void
    {
        self::assertNull($this->resolver->resolveDocument($params));
        self::assertNull($this->resolver->resolvePositioned($params + ['position' => ['line' => 0, 'character' => 0]]));
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidDocumentParamsProvider(): iterable
    {
        yield 'missing text document' => [[]];
        yield 'invalid text document' => [['textDocument' => 'invalid']];
        yield 'missing URI' => [['textDocument' => []]];
        yield 'invalid URI' => [['textDocument' => ['uri' => 1]]];
        yield 'unknown document' => [['textDocument' => ['uri' => 'file:///workspace/config/unknown.yaml']]];
    }

    public function testRejectsADocumentOutsideAProject(): void
    {
        $document = new Document('file:///outside/config/services.yaml', 'yaml', 1, 'services: {}');
        $this->documents->open($document);
        $params = ['textDocument' => ['uri' => $document->uri()]];

        self::assertNull($this->resolver->resolveDocument($params));
        self::assertNull($this->resolver->resolvePositioned($params + ['position' => ['line' => 0, 'character' => 0]]));
    }

    /** @param array<array-key, mixed> $position */
    #[DataProvider('invalidPositionProvider')]
    public function testRejectsInvalidPositions(array $position): void
    {
        $params = [
            'textDocument' => ['uri' => $this->document->uri()],
            'position' => $position,
        ];

        self::assertInstanceOf(DocumentContext::class, $this->resolver->resolveDocument($params));
        self::assertNull($this->resolver->resolvePositioned($params));
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidPositionProvider(): iterable
    {
        yield 'missing line' => [['character' => 0]];
        yield 'missing character' => [['line' => 0]];
        yield 'invalid line' => [['line' => '0', 'character' => 0]];
        yield 'invalid character' => [['line' => 0, 'character' => '0']];
        yield 'negative line' => [['line' => -1, 'character' => 0]];
        yield 'negative character' => [['line' => 0, 'character' => -1]];
    }

    public function testRejectsAMissingPosition(): void
    {
        $params = ['textDocument' => ['uri' => $this->document->uri()]];

        self::assertInstanceOf(DocumentContext::class, $this->resolver->resolveDocument($params));
        self::assertNull($this->resolver->resolvePositioned($params));
    }
}
