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
use Symfony\Lsp\Index\SourceParseHealth;
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

    public function testTreatsAnUnreadableCachedPayloadAsAChangedDomain(): void
    {
        $pipeline = new SourceIndexProviderPipeline(new SourceIndexPayloadCodec(), [
            new FirstPipelineProvider('first', [PipelineFacts::class], new PipelineFacts()),
        ]);

        $replacement = $pipeline->replace(
            new Project('/workspace', 'file:///workspace'),
            new SourceDocument('file:///source.php', 'php', ''),
            ['first' => 'not a payload'],
        );

        self::assertTrue($replacement->factsChanged);
        self::assertSame(['first'], $replacement->changedProviders);
    }

    public function testSurfacesProviderFailuresInsteadOfCountingThemAsChangedDomains(): void
    {
        $codec = new SourceIndexPayloadCodec();
        $pipeline = new SourceIndexProviderPipeline($codec, [
            new FailingPipelineProvider('first', [PipelineFacts::class], new PipelineFacts()),
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The "first" provider cannot project its facts.');

        $pipeline->replace(
            new Project('/workspace', 'file:///workspace'),
            new SourceDocument('file:///source.php', 'php', ''),
            ['first' => $codec->encode('first', new PipelineFacts('file:///previous.php'))],
        );
    }
}

abstract class AbstractPipelineProvider implements SourceIndexProviderInterface
{
    /** @param list<string> $classes */
    public function __construct(
        private readonly string $providerName,
        private readonly array $classes,
        private readonly ?SourceFactsInterface $facts = null,
    ) {
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
        return $this->facts;
    }

    public function runtimeRefreshProjection(mixed $data): array
    {
        return [];
    }

    public function remove(Project $project, string $uri): void
    {
    }

    public function overlay(Project $project, Document $document, SourceParseHealth $health): void
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

final class FailingPipelineProvider extends AbstractPipelineProvider
{
    public function runtimeRefreshProjection(mixed $data): array
    {
        throw new \UnexpectedValueException(\sprintf('The "%s" provider cannot project its facts.', $this->name()));
    }
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
