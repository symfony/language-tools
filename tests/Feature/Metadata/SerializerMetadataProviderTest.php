<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Metadata\MetadataCompletionProvider;
use Symfony\Lsp\Feature\Metadata\MetadataSymbolKind;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class SerializerMetadataProviderTest extends MetadataTestCase
{
    public function testCompletesSerializerGroupReferences(): void
    {
        $kit = (new ProjectTestKit())->open('file:///workspace/src/Entity/User.php', <<<'PHP'
            <?php
            namespace App\Entity;
            use Symfony\Component\Serializer\Attribute\Groups;
            final class User
            {
                #[Groups(['admin'])]
                public string $email;
            }
            PHP)->index();
        $groupUri = 'file:///workspace/src/Serializer.php';
        $groupText = "<?php\n\$context = ['groups' => ['ad";
        $kit->open($groupUri, $groupText);

        self::assertSame(['admin'], $kit->labels($kit->get(MetadataCompletionProvider::class)->complete($kit->positioned($kit->offset($groupUri, \strlen($groupText))))));
    }

    public function testIndexesGroupReferencesOnlyInSerializerContexts(): void
    {
        $extractor = $this->extractor();
        $text = <<<'PHP'
            <?php
            namespace App\Controller;

            use Symfony\Component\Serializer\Attribute\Context;
            use Symfony\Component\Serializer\Attribute\Groups;
            use Symfony\Component\Serializer\SerializerInterface;
            use Symfony\Component\Validator\Constraints as Assert;

            final class OrderController
            {
                #[Groups(['order:read'])]
                #[Assert\NotBlank(['groups' => ['registration_step_two']])]
                #[Context(normalizationContext: ['groups' => ['order:context']])]
                public string $reference = '';

                public function __construct(private SerializerInterface $serializer, private \PDO $connection) {}

                public function show(object $order): array
                {
                    $payload = $this->serializer->serialize($order, 'json', ['groups' => ['order:serialize']]);
                    $this->connection->normalize($order, null, ['groups' => ['pdo:ignored']]);
                    $settings = ['groups' => ['plain:ignored']];

                    return [$payload, $this->json($order, 200, [], ['groups' => ['order:json']]), $settings];
                }
            }
            PHP;

        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Controller/OrderController.php', 'php', $text));

        self::assertSame(
            [
                ['order:read', true],
                ['order:context', false],
                ['order:serialize', false],
                ['order:json', false],
            ],
            array_values(array_map(
                static fn ($symbol): array => [$symbol->name, $symbol->declaration],
                array_filter($facts->symbols, static fn ($symbol): bool => MetadataSymbolKind::SerializerGroup === $symbol->kind),
            )),
        );
    }

    #[DataProvider('serializerGroupsAttributeCompletionProvider')]
    public function testCompletesSerializerGroupsOnlyInResolvedGroupsAttributes(string $text, ?string $expectedPrefix): void
    {
        $extractor = $this->extractor();

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
        $extractor = $this->extractor();
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
        $extractor = $this->extractor();
        $text = "<?php // #[Groups(['adm";

        self::assertNull($extractor->completionContext('php', $text, \strlen($text)));
    }
}
