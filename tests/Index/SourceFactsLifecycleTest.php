<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\AbstractSourceFactsIndex;
use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsIndexInterface;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

final class SourceFactsLifecycleTest extends TestCase
{
    public function testRemovingANonexistentOverlayDoesNotInvalidateDerivedState(): void
    {
        $index = new CountingSourceFactsIndex();
        $index->replace(new LifecycleSourceFacts('file:///source.php', 'saved'));

        self::assertSame(['saved'], $index->values());
        self::assertSame(1, $index->builds());

        $index->removeOverlay('file:///source.php');

        self::assertSame(['saved'], $index->values());
        self::assertSame(1, $index->builds());
    }

    public function testRemovingAnExistingOverlayInvalidatesDerivedState(): void
    {
        $index = new CountingSourceFactsIndex();
        $index->replace(new LifecycleSourceFacts('file:///source.php', 'saved'));
        $index->overlay(new LifecycleSourceFacts('file:///source.php', 'overlay'));

        self::assertSame(['overlay'], $index->values());
        self::assertSame(1, $index->builds());

        $index->removeOverlay('file:///source.php');

        self::assertSame(['saved'], $index->values());
        self::assertSame(2, $index->builds());
    }

    public function testReplacingAnOverlayWithIdenticalFactsKeepsDerivedStateCorrect(): void
    {
        $index = new CountingSourceFactsIndex();
        $index->overlay(new LifecycleSourceFacts('file:///source.php', 'overlay'));

        self::assertSame(['overlay'], $index->values());
        self::assertSame(1, $index->builds());

        $index->overlay(new LifecycleSourceFacts('file:///source.php', 'overlay'));

        self::assertSame(['overlay'], $index->values());
        self::assertSame(1, $index->builds());
    }

    #[DataProvider('documentWithoutFactsProvider')]
    public function testOverlayMirrorsTheCurrentDocumentOrIsRemoved(Document $currentDocument): void
    {
        $index = new CountingSourceFactsIndex();
        $indexer = new LifecycleSourceIndexer($index);
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $indexer->overlay($project, new Document('file:///source.php', 'php', 1, 'overlay'));

        self::assertSame(['overlay'], $index->values());

        $indexer->overlay($project, $currentDocument);

        self::assertSame([], $index->values());
    }

    /** @return iterable<string, array{Document}> */
    public static function documentWithoutFactsProvider(): iterable
    {
        yield 'extractor returns null' => [new Document('file:///source.php', 'php', 2, '')];
        yield 'overlays are unsupported' => [new Document('file:///source.php', 'unsupported', 2, 'current')];
    }
}

/** @extends AbstractSourceFactsIndex<LifecycleSourceFacts> */
final class CountingSourceFactsIndex extends AbstractSourceFactsIndex
{
    /** @var list<string>|null */
    private ?array $values = null;
    private int $builds = 0;

    /** @return list<string> */
    public function values(): array
    {
        if (null === $this->values) {
            ++$this->builds;
            $this->values = array_map(static fn (LifecycleSourceFacts $facts): string => $facts->value, $this->facts());
        }

        return $this->values;
    }

    public function builds(): int
    {
        return $this->builds;
    }

    protected function factsChanged(): void
    {
        $this->values = null;
    }
}

/** @extends AbstractSourceIndexer<LifecycleSourceFacts> */
final class LifecycleSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(private readonly CountingSourceFactsIndex $index)
    {
    }

    public function name(): string
    {
        return 'lifecycle';
    }

    public function payloadClasses(): array
    {
        return [LifecycleSourceFacts::class];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        return [];
    }

    protected function factsClass(): string
    {
        return LifecycleSourceFacts::class;
    }

    protected function sourceIndex(Project $project): SourceFactsIndexInterface
    {
        return $this->index;
    }

    protected function extract(Project $project, SourceDocument $document): ?SourceFactsInterface
    {
        return '' === $document->text ? null : new LifecycleSourceFacts($document->uri, $document->text);
    }

    protected function supportsOverlay(Project $project, Document $document): bool
    {
        return 'unsupported' !== $document->languageId;
    }
}

final class LifecycleSourceFacts implements SourceFactsInterface
{
    public function __construct(public readonly string $uri, public readonly string $value)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function isEmpty(): bool
    {
        return '' === $this->value;
    }
}
