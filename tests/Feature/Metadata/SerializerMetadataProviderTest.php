<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Metadata\MetadataCompletionProvider;
use Symfony\Lsp\Feature\Metadata\MetadataIndexRegistry;
use Symfony\Lsp\Feature\Metadata\MetadataSourceIndexRegistry;
use Symfony\Lsp\Feature\Metadata\MetadataSymbolKind;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class SerializerMetadataProviderTest extends MetadataTestCase
{
    public function testCompletesSerializerGroupReferences(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);
        $project = new Project('/workspace', 'file:///workspace');
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $entityText = <<<'PHP'
            <?php
            namespace App\Entity;
            use Symfony\Component\Serializer\Attribute\Groups;
            final class User
            {
                #[Groups(['admin'])]
                public string $email;
            }
            PHP;
        $sourceIndexes = new MetadataSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace($extractor->extract(new SourceDocument('file:///workspace/src/Entity/User.php', 'php', $entityText)));
        $documents = new DocumentStore();
        $resolver = new DocumentContextResolver($documents, $projects);
        $completionProvider = new MetadataCompletionProvider($resolver, $converter, new LspProtocolMapper(), new MetadataIndexRegistry(), $sourceIndexes, $extractor);
        $groupUri = 'file:///workspace/src/Serializer.php';
        $groupText = "<?php\n\$context = ['groups' => ['ad";
        $documents->open(new Document($groupUri, 'php', 1, $groupText));

        self::assertSame(['admin'], $this->completionLabels($completionProvider, $converter, $groupUri, $groupText, \strlen($groupText)));
    }

    #[DataProvider('serializerGroupsAttributeCompletionProvider')]
    public function testCompletesSerializerGroupsOnlyInResolvedGroupsAttributes(string $text, ?string $expectedPrefix): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);

        self::assertSame($expectedPrefix, $extractor->completionContext('php', $text, \strlen($text))?->prefix);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function serializerGroupsAttributeCompletionProvider(): iterable
    {
        yield 'aliased attribute' => [<<<'PHP'
            <?php
            use Symfony\Component\Serializer\Attribute\Groups as Serializer;

            #[Serializer(['adm
            PHP, 'adm'];
        yield 'fully qualified attribute' => [<<<'PHP'
            <?php
            #[\Symfony\Component\Serializer\Attribute\Groups(['adm
            PHP, 'adm'];
        yield 'unrelated attribute with the same short name' => [<<<'PHP'
            <?php
            use App\Attribute\Groups;

            #[Groups(['adm
            PHP, null];
    }

    public function testIgnoresCommentedSerializerMetadataWhilePreservingActiveRanges(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);
        $text = <<<'PHP'
            <?php
            namespace App\Dto;

            use Symfony\Component\Serializer\Attribute\Groups;

            final class Input
            {
                // #[Groups(['commented_group'])]
                #[Groups(['active_group'])]
                public string $value;
            }
            PHP;

        $symbols = $extractor->extract(new SourceDocument('file:///workspace/src/Dto/Input.php', 'php', $text))->symbols;
        $serializerGroups = [];
        foreach ($symbols as $symbol) {
            self::assertStringNotContainsString('commented_', $symbol->name);
            if (MetadataSymbolKind::SerializerGroup === $symbol->kind) {
                $serializerGroups[] = $symbol;
            }
        }
        self::assertCount(1, $serializerGroups);
        self::assertSame('active_group', $serializerGroups[0]->name);
        self::assertSame(strpos($text, 'active_group'), $converter->toByteOffset($text, $serializerGroups[0]->range->start));
    }

    public function testOffersNoMetadataCompletionsInsidePhpComments(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);
        $text = "<?php // #[Groups(['adm";

        self::assertNull($extractor->completionContext('php', $text, \strlen($text)));
    }
}
