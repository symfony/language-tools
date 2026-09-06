<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Metadata\MetadataSourceFacts;
use Symfony\Lsp\Feature\Metadata\MetadataSourceIndexer;
use Symfony\Lsp\Feature\Metadata\MetadataSourceIndexRegistry;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Project\Project;

final class MetadataSourceIndexerTest extends MetadataTestCase
{
    public function testRestoresPersistedOptionFacts(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $uri = 'file:///workspace/src/Controller/EventController.php';
        $document = new SourceDocument($uri, 'php', <<<'PHP'
            <?php
            namespace App\Controller;

            use App\Form\EventType;
            use Symfony\Component\Validator\Constraints as Assert;

            #[Assert\Length(max: 120)]
            final class EventController
            {
                public function create(): void
                {
                    $this->createForm(EventType::class, null, ['required' => true]);
                }
            }
            PHP);
        $indexes = new MetadataSourceIndexRegistry();
        $indexer = new MetadataSourceIndexer($indexes, $this->createExtractor(new PositionConverter()));
        $indexer->begin($project);
        $facts = $indexer->index($project, $document);
        self::assertInstanceOf(MetadataSourceFacts::class, $facts);
        $indexer->finish($project);

        $codec = new SourceIndexPayloadCodec();
        $codec->validate([$indexer]);
        $payload = $codec->encode($indexer->name(), $facts);
        $restoredIndexes = new MetadataSourceIndexRegistry();
        $restored = new MetadataSourceIndexer($restoredIndexes, $this->createExtractor(new PositionConverter()));
        $restored->begin($project);
        $restored->restore($project, $codec->decode($indexer->name(), $payload));
        $restored->finish($project);

        $restoredFacts = $restoredIndexes->forProject($project)->factsForUri($uri);
        self::assertInstanceOf(MetadataSourceFacts::class, $restoredFacts);
        self::assertSame([['App\\Form\\EventType', 'required']], array_map(
            static fn ($option): array => [$option->className, $option->option],
            $restoredFacts->formOptions,
        ));
        self::assertSame([['Symfony\\Component\\Validator\\Constraints\\Length', 'max']], array_map(
            static fn ($option): array => [$option->constraint, $option->option],
            $restoredFacts->constraintOptions,
        ));
    }
}
