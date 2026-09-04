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
use Symfony\Lsp\Index\SourceParseHealth;
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

    public function testExposesEffectiveFactsByUri(): void
    {
        $index = new CountingSourceFactsIndex();
        $uri = 'file:///source.php';
        $index->replace(new LifecycleSourceFacts($uri, 'saved'));

        self::assertSame('saved', $index->factsForUri($uri)?->declaration);

        $index->overlay(new LifecycleSourceFacts($uri, 'overlay'));
        self::assertSame('overlay', $index->factsForUri($uri)?->declaration);
    }

    #[DataProvider('documentWithoutFactsProvider')]
    public function testOverlayMirrorsTheCurrentDocumentOrIsRemoved(Document $currentDocument): void
    {
        $index = new CountingSourceFactsIndex();
        $indexer = new LifecycleSourceIndexer($index);
        $project = new Project('/workspace', 'file:///workspace');
        $indexer->overlay($project, new Document('file:///source.php', 'php', 1, 'overlay'), SourceParseHealth::Healthy);

        self::assertSame(['overlay'], $index->values());

        $indexer->overlay($project, $currentDocument, SourceParseHealth::Healthy);

        self::assertSame([], $index->values());
    }

    public function testPartialOverlayPreservesHealthyDeclarationsAndUsesCurrentReferences(): void
    {
        $index = new CountingSourceFactsIndex();
        $indexer = new LifecycleSourceIndexer($index);
        $project = new Project('/workspace', 'file:///workspace');
        $uri = 'file:///workspace/source.php';

        $indexer->overlay($project, new Document($uri, 'php', 1, 'healthy|old-reference'), SourceParseHealth::Healthy);
        $indexer->overlay($project, new Document($uri, 'php', 2, 'partial|current-reference'), SourceParseHealth::Partial);

        self::assertSame(['healthy'], $index->values());
        self::assertSame(['current-reference'], $index->references());
    }

    public function testFirstPartialOverlayUsesCurrentFacts(): void
    {
        $index = new CountingSourceFactsIndex();
        $indexer = new LifecycleSourceIndexer($index);
        $project = new Project('/workspace', 'file:///workspace');
        $uri = 'file:///workspace/source.php';

        $indexer->overlay($project, new Document($uri, 'php', 1, 'partial|current-reference'), SourceParseHealth::Partial);

        self::assertSame(['partial'], $index->values());
        self::assertSame(['current-reference'], $index->references());
    }

    public function testHealthyOverlaySurvivesSavedReplacementAndFullScanReapply(): void
    {
        $index = new CountingSourceFactsIndex();
        $indexer = new LifecycleSourceIndexer($index);
        $project = new Project('/workspace', 'file:///workspace');
        $uri = 'file:///workspace/source.php';

        $indexer->overlay($project, new Document($uri, 'php', 1, 'healthy|old-reference'), SourceParseHealth::Healthy);
        $indexer->replace($project, new SourceDocument($uri, 'php', 'saved|saved-reference'));
        $indexer->overlay($project, new Document($uri, 'php', 2, 'partial|after-save'), SourceParseHealth::Partial);
        self::assertSame(['healthy'], $index->values());
        self::assertSame(['after-save'], $index->references());

        $indexer->begin($project);
        $indexer->index($project, new SourceDocument($uri, 'php', 'scanned|scanned-reference'));
        $indexer->finish($project);
        $indexer->overlay($project, new Document($uri, 'php', 3, 'partial|after-scan'), SourceParseHealth::Partial);
        self::assertSame(['healthy'], $index->values());
        self::assertSame(['after-scan'], $index->references());
    }

    public function testClosingOrRemovingAProjectClearsHealthyOverlayFacts(): void
    {
        $index = new CountingSourceFactsIndex();
        $indexer = new LifecycleSourceIndexer($index);
        $project = new Project('/workspace', 'file:///workspace');
        $uri = 'file:///workspace/source.php';

        $indexer->overlay($project, new Document($uri, 'php', 1, 'first-healthy'), SourceParseHealth::Healthy);
        $indexer->removeOverlay($project, $uri);
        $indexer->overlay($project, new Document($uri, 'php', 2, 'after-close'), SourceParseHealth::Partial);
        self::assertSame(['after-close'], $index->values());

        $indexer->overlay($project, new Document($uri, 'php', 3, 'second-healthy'), SourceParseHealth::Healthy);
        $indexer->removeProject($project);
        $indexer->overlay($project, new Document($uri, 'php', 4, 'after-removal'), SourceParseHealth::Partial);
        self::assertSame(['after-removal'], $index->values());
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
            $this->values = array_map(static fn (LifecycleSourceFacts $facts): string => $facts->declaration, $this->facts());
        }

        return $this->values;
    }

    /** @return list<string> */
    public function references(): array
    {
        return array_map(static fn (LifecycleSourceFacts $facts): string => $facts->reference, $this->facts());
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
        if ('' === $document->text) {
            return null;
        }
        [$declaration, $reference] = array_pad(explode('|', $document->text, 2), 2, '');

        return new LifecycleSourceFacts($document->uri, $declaration, $reference);
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): SourceFactsInterface
    {
        return new LifecycleSourceFacts($current->uri, $healthy->declaration, $current->reference);
    }

    protected function supportsOverlay(Project $project, Document $document): bool
    {
        return 'unsupported' !== $document->languageId;
    }
}

final class LifecycleSourceFacts implements SourceFactsInterface
{
    public function __construct(
        public readonly string $uri,
        public readonly string $declaration,
        public readonly string $reference = '',
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function isEmpty(): bool
    {
        return '' === $this->declaration && '' === $this->reference;
    }
}
