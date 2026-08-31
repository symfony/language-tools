<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Index\SourceIndexProviderPipeline;
use Symfony\Lsp\Project\Project;

final class SourceIndexProviderPipelineTest extends TestCase
{
    /** @param list<string> $classes */
    #[DataProvider('invalidPayloadSchemaProvider')]
    public function testRejectsInvalidPayloadSchemas(array $classes): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SourceIndexProviderPipeline(new SourceIndexPayloadCodec(), [new FirstPipelineProvider('first', $classes)]);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalidPayloadSchemaProvider(): iterable
    {
        yield 'empty' => [[]];
        yield 'unknown class' => [['MissingPayloadClass']];
        yield 'duplicate class' => [[PipelineFacts::class, PipelineFacts::class]];
        yield 'shared class' => [[Position::class]];
    }

    public function testRejectsPayloadClassesOwnedByDifferentProviders(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SourceIndexProviderPipeline(new SourceIndexPayloadCodec(), [
            new FirstPipelineProvider('first', [PipelineFacts::class]),
            new SecondPipelineProvider('second', [PipelineFacts::class]),
        ]);
    }
}

abstract class AbstractPipelineProvider implements SourceIndexProviderInterface
{
    /** @param list<string> $classes */
    public function __construct(private readonly string $providerName, private readonly array $classes)
    {
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function payloadClasses(): array
    {
        return $this->classes;
    }

    public function begin(Project $project): void
    {
    }

    public function index(Project $project, SourceDocument $document): ?SourceFactsInterface
    {
        return null;
    }

    public function restore(Project $project, mixed $data): void
    {
    }

    public function finish(Project $project): void
    {
    }

    public function replace(Project $project, SourceDocument $document): ?SourceFactsInterface
    {
        return null;
    }

    public function runtimeDeclarations(mixed $data): array
    {
        return [];
    }

    public function remove(Project $project, string $uri): void
    {
    }

    public function overlay(Project $project, Document $document): void
    {
    }

    public function removeOverlay(Project $project, string $uri): void
    {
    }
}

final class FirstPipelineProvider extends AbstractPipelineProvider
{
}

final class SecondPipelineProvider extends AbstractPipelineProvider
{
}

final class PipelineFacts implements SourceFactsInterface
{
    public function __construct(public readonly string $uri = 'file:///source.php')
    {
    }

    public function isEmpty(): bool
    {
        return false;
    }
}
