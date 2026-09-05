<?php

namespace Symfony\Lsp\Tests\Feature\Doctrine;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Doctrine\DoctrineCompletionProvider;
use Symfony\Lsp\Feature\Doctrine\DoctrineEntity;
use Symfony\Lsp\Feature\Doctrine\DoctrineExtractor;
use Symfony\Lsp\Feature\Doctrine\DoctrineField;
use Symfony\Lsp\Feature\Doctrine\DoctrineFieldCompletionBuilder;
use Symfony\Lsp\Feature\Doctrine\DoctrineIndexRegistry;
use Symfony\Lsp\Feature\Doctrine\DoctrineRelationshipCodeLensProvider;
use Symfony\Lsp\Feature\Doctrine\DoctrineRelationshipProvider;
use Symfony\Lsp\Feature\Doctrine\DoctrineRepositoryReceiverResolver;
use Symfony\Lsp\Feature\Doctrine\DoctrineSymbolKind;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpCapturedReceiverResolver;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpLiteralArrayKeyParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class DoctrineProviderTest extends TestCase
{
    public function testCompletesAndNavigatesEntityFieldsAndRepositoryMappings(): void
    {
        $entityUri = 'file:///workspace/src/Entity/Product.php';
        $entityText = <<<'PHP'
            <?php
            namespace App\Entity;

            use App\Repository\{ProductRepository};
            use Doctrine\ORM\{Mapping as ORM};

            #[ORM\Entity(repositoryClass: ProductRepository::class)]
            class Product
            {
                #[ORM\Id]
                #[ORM\Column]
                private ?int $id = null;

                #[ORM\Column(length: 255)]
                private string $name;

                #[ORM\ManyToOne(targetEntity: Category::class)]
                private ?Category $category = null;

                private string $transient;
            }
            PHP;
        $repositoryUri = 'file:///workspace/src/Repository/ProductRepository.php';
        $repositoryText = <<<'PHP'
            <?php
            namespace App\Repository;

            use App\Entity\{Product};
            use Doctrine\{Bundle\DoctrineBundle\Repository\ServiceEntityRepository, Persistence\ManagerRegistry};

            class ProductRepository extends ServiceEntityRepository
            {
                public function __construct(ManagerRegistry $registry)
                {
                    parent::__construct($registry, Product::class);
                }
            }
            PHP;
        $usageUri = 'file:///workspace/src/Form/ProductType.php';
        $usageText = <<<'PHP'
            <?php
            namespace App\Form;

            use App\{Entity\Product, Repository\ProductRepository};
            use Doctrine\ORM\{EntityManagerInterface};
            use Symfony\{Bridge\Doctrine\Form\Type\EntityType, Component\Form\FormBuilderInterface};

            final class ProductType
            {
                public function build(FormBuilderInterface $builder, ProductRepository $products, EntityManagerInterface $entityManager): void
                {
                    $builder->add('product', EntityType::class, [
                        'class' => Product::class,
                        'choice_label' => 'name',
                    ]);
                    $products->findOneBy(['name' => 'Symfony']);
                    $entityManager->getRepository(Product::class)->findBy(['category' => null]);
                }
            }
            PHP;
        $formCompletionUri = 'file:///workspace/src/Form/FormCompletion.php';
        $formCompletionText = <<<'PHP'
            <?php
            use App\Entity\{Product};
            use Symfony\Bridge\Doctrine\Form\Type\{EntityType};
            $builder->add('product', EntityType::class, [
                'class' => Product::class,
                'choice_label' => 'na
            PHP;
        $repositoryCompletionUri = 'file:///workspace/src/RepositoryCompletion.php';
        $repositoryCompletionText = <<<'PHP'
            <?php
            use App\Repository\ProductRepository;
            function find(ProductRepository $products): void {
                $products->findBy(['na
            PHP;
        $managerCompletionUri = 'file:///workspace/src/ManagerCompletion.php';
        $managerCompletionText = <<<'PHP'
            <?php
            use App\Entity\Product;
            $repository = $manager->getRepository(Product::class);
            $repository->findBy(['ca
            PHP;

        $converter = new PositionConverter();
        $extractor = $this->extractor($converter);
        $project = new Project('/workspace', 'file:///workspace');
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $indexes = new DoctrineIndexRegistry();
        $index = $indexes->forProject($project);
        $index->replace(
            $extractor->extract(new SourceDocument($entityUri, 'php', $entityText)),
            $extractor->extract(new SourceDocument($repositoryUri, 'php', $repositoryText)),
            $extractor->extract(new SourceDocument($usageUri, 'php', $usageText)),
        );
        $fieldNames = [];
        foreach ($index->entity('App\\Entity\\Product')->fields ?? [] as $field) {
            $fieldNames[] = $field->name;
        }
        self::assertSame(['id', 'name', 'category'], $fieldNames);
        self::assertSame('App\\Entity\\Product', $index->repository('App\\Repository\\ProductRepository')?->entityClass);

        $documents = new DocumentStore();
        foreach ([
            [$entityUri, $entityText],
            [$repositoryUri, $repositoryText],
            [$usageUri, $usageText],
            [$formCompletionUri, $formCompletionText],
            [$repositoryCompletionUri, $repositoryCompletionText],
            [$managerCompletionUri, $managerCompletionText],
        ] as [$uri, $text]) {
            $documents->open(new Document($uri, 'php', 1, $text));
        }
        $resolver = new DocumentContextResolver($documents, $projects);
        $protocol = new LspProtocolMapper();
        $completionBuilder = new DoctrineFieldCompletionBuilder($protocol);
        $completionProvider = new DoctrineCompletionProvider($resolver, $converter, $indexes, $extractor, $completionBuilder);
        $relationshipProvider = new DoctrineRelationshipProvider($resolver, new PositionedSourceSymbolResolver($converter), $protocol, $indexes, $extractor);
        $codeLensProvider = new DoctrineRelationshipCodeLensProvider($resolver, $protocol, $indexes, $extractor);

        self::assertSame(['name'], array_column($completionProvider->complete($this->params($converter, $formCompletionUri, $formCompletionText, \strlen($formCompletionText))) ?? [], 'label'));
        self::assertSame(['name'], array_column($completionProvider->complete($this->params($converter, $repositoryCompletionUri, $repositoryCompletionText, \strlen($repositoryCompletionText))) ?? [], 'label'));
        self::assertSame(['category'], array_column($completionProvider->complete($this->params($converter, $managerCompletionUri, $managerCompletionText, \strlen($managerCompletionText))) ?? [], 'label'));

        $fieldParams = $this->params($converter, $usageUri, $usageText, strpos($usageText, "['name'") + 3);
        self::assertSame([$entityUri], array_column($relationshipProvider->definition($fieldParams) ?? [], 'uri'));
        self::assertCount(3, $relationshipProvider->references($fieldParams) ?? []);
        $fieldHover = $relationshipProvider->hover($fieldParams);
        self::assertIsArray($fieldHover);
        self::assertIsArray($fieldHover['contents'] ?? null);
        self::assertIsString($fieldHover['contents']['value'] ?? null);
        self::assertStringContainsString('Doctrine field: `App\\Entity\\Product::$name`', $fieldHover['contents']['value']);

        $repositoryParams = $this->params($converter, $entityUri, $entityText, strpos($entityText, 'ProductRepository::class') + 2);
        self::assertSame([$repositoryUri], array_column($relationshipProvider->definition($repositoryParams) ?? [], 'uri'));
        $entityParams = $this->params($converter, $repositoryUri, $repositoryText, strpos($repositoryText, 'Product::class') + 2);
        self::assertSame([$entityUri], array_column($relationshipProvider->definition($entityParams) ?? [], 'uri'));

        $entityLenses = $codeLensProvider->codeLenses(['textDocument' => ['uri' => $entityUri]]);
        self::assertIsArray($entityLenses);
        self::assertIsArray($entityLenses[0]['command'] ?? null);
        self::assertSame('Repository: App\\Repository\\ProductRepository', $entityLenses[0]['command']['title'] ?? null);
        $repositoryLenses = $codeLensProvider->codeLenses(['textDocument' => ['uri' => $repositoryUri]]);
        self::assertIsArray($repositoryLenses);
        self::assertIsArray($repositoryLenses[0]['command'] ?? null);
        self::assertSame('Entity: App\\Entity\\Product', $repositoryLenses[0]['command']['title'] ?? null);
    }

    public function testMapsOnlyCompleteClassReferencesInMappingAttributes(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Entity/Product.php', 'php', <<<'PHP'
            <?php
            namespace App\Entity;

            use App\Repository\ProductRepository;
            use Doctrine\ORM\Mapping as ORM;

            #[ORM\Entity(repositoryClass: ProductRepository /* repository */ ::class)]
            final class Product
            {
                #[ORM\ManyToOne(targetEntity: (Category::class))]
                private mixed $parenthesized;

                #[ORM\ManyToOne(targetEntity: Category::class)]
                private mixed $category;
            }
            PHP));

        self::assertSame('App\Repository\ProductRepository', $facts->entities[0]->repositoryClass);
        self::assertSame(
            [null, 'App\Entity\Category'],
            array_map(static fn ($field): ?string => $field->targetEntity, $facts->entities[0]->fields),
        );
    }

    public function testIgnoresClassReferencesEmbeddedInMappingExpressions(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Entity/Product.php', 'php', <<<'PHP'
            <?php
            namespace App\Entity;

            use App\Repository\ProductRepository;
            use Doctrine\ORM\Mapping as ORM;

            #[ORM\Entity(repositoryClass: REPOSITORY_PREFIX . ProductRepository::class)]
            final class Product
            {
                #[ORM\ManyToOne(targetEntity: Category::class . ENTITY_SUFFIX)]
                private mixed $category;
            }
            PHP));

        self::assertNull($facts->entities[0]->repositoryClass);
        self::assertNull($facts->entities[0]->fields[0]->targetEntity);
        self::assertSame([], $facts->repositories);
        self::assertSame([], array_values(array_filter(
            $facts->symbols,
            static fn ($symbol): bool => DoctrineSymbolKind::Repository === $symbol->kind,
        )));
    }

    public function testRequiresGetRepositoryToBeTheTerminalReceiverCall(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Service/ProductFinder.php', 'php', <<<'PHP'
            <?php
            namespace App\Service;

            use App\Entity\Product;

            final class ProductFinder
            {
                public function find(object $manager): void
                {
                    $manager->getRepository(Product::class)->findBy(['direct' => true]);
                    $manager->getRepository(Product::class)->differentRepository()->findBy(['changed' => true]);
                }
            }
            PHP));

        self::assertSame(
            ['direct'],
            array_map(
                static fn ($symbol): string => $symbol->name,
                array_values(array_filter($facts->symbols, static fn ($symbol): bool => DoctrineSymbolKind::Field === $symbol->kind)),
            ),
        );
    }

    public function testUsesPropertyPlacementAndScopedRepositoryReceivers(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->extractor($converter);
        $entityText = <<<'PHP'
            <?php
            namespace App\Entity;

            use Doctrine\ORM\Mapping as ORM;

            #[ORM\Entity]
            final class Product
            {
                #[ORM\Column]
                private string $name, $sku;
            }
            PHP;
        $repositoryText = <<<'PHP'
            <?php
            namespace App\Repository;

            use App\Entity\Product;
            use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

            /** @extends ServiceEntityRepository<Product> */
            final class ProductRepository extends ServiceEntityRepository
            {
            }
            PHP;
        $usageText = <<<'PHP'
            <?php
            namespace App\Service;

            use App\Repository\ProductRepository;

            final class ProductFinder
            {
                public function find(ProductRepository $products): void
                {
                    $products->findBy(['name' => 'Symfony']);
                }

                public function unrelated(object $products): void
                {
                    $products->findBy(['ignored' => true]);
                }

                public function ambiguous(ProductRepository|\stdClass $products): void
                {
                    $products->findBy(['ambiguous' => true]);
                }
            }
            PHP;

        $entityFacts = $extractor->extract(new SourceDocument('file:///workspace/src/Entity/Product.php', 'php', $entityText));
        self::assertSame(['name', 'sku'], array_map(static fn (DoctrineField $field): string => $field->name, $entityFacts->entities[0]->fields));

        $repositoryFacts = $extractor->extract(new SourceDocument('file:///workspace/src/Repository/ProductRepository.php', 'php', $repositoryText));
        self::assertSame('App\Entity\Product', $repositoryFacts->repositories[0]->entityClass);

        $usageFacts = $extractor->extract(new SourceDocument('file:///workspace/src/Service/ProductFinder.php', 'php', $usageText));
        $fieldReferences = array_values(array_filter(
            $usageFacts->symbols,
            static fn ($symbol): bool => DoctrineSymbolKind::Field === $symbol->kind,
        ));
        self::assertSame(['name'], array_map(static fn ($symbol): string => $symbol->name, $fieldReferences));
        self::assertSame(strpos($usageText, "'name'") + 1, $converter->toByteOffset($usageText, $fieldReferences[0]->range->start));
    }

    public function testIndexesRepositoryCriteriaCapturedInsideClosures(): void
    {
        $facts = $this->extractor()->extract(new SourceDocument('file:///workspace/src/Service/ProductFinder.php', 'php', <<<'PHP'
            <?php
            namespace App\Service;

            use App\Entity\Product;
            use App\Repository\ProductRepository;

            final class ProductFinder
            {
                public function find(ProductRepository $products, object $manager): void
                {
                    $closure = function () use ($products): void {
                        $products->findBy(['closure' => true]);
                    };
                    $arrow = fn () => $products->findBy(['arrow' => true]);
                    $repository = $manager->getRepository(Product::class);
                    $assignedClosure = function () use ($repository): void {
                        $repository->findBy(['assigned_closure' => true]);
                    };
                    $assignedArrow = fn () => $repository->findBy(['assigned_arrow' => true]);
                    $uncaptured = function (): void {
                        $products->findBy(['uncaptured' => true]);
                    };
                    $shadowed = fn ($products) => $products->findBy(['shadowed' => true]);
                }
            }
            PHP));

        $references = array_values(array_filter(
            $facts->symbols,
            static fn ($symbol): bool => DoctrineSymbolKind::Field === $symbol->kind,
        ));
        self::assertSame(['closure', 'arrow', 'assigned_closure', 'assigned_arrow'], array_map(static fn ($symbol): string => $symbol->name, $references));
        self::assertSame([
            'App\Repository\ProductRepository',
            'App\Repository\ProductRepository',
            'App\Entity\Product',
            'App\Entity\Product',
        ], array_map(static fn ($symbol): ?string => $symbol->owner, $references));
    }

    public function testRecognizesPromotedMappedFields(): void
    {
        $extractor = $this->extractor();
        $text = <<<'PHP'
            <?php
            namespace App\Entity;

            use Doctrine\ORM\Mapping as ORM;

            #[ORM\Entity]
            final class Product
            {
                public function __construct(
                    #[ORM\Column]
                    public readonly string $name,
                    #[ORM\ManyToOne]
                    public readonly ?Category $category,
                ) {
                }
            }
            PHP;

        $fields = $extractor->extract(new SourceDocument('file:///workspace/src/Entity/Product.php', 'php', $text))->entities[0]->fields;

        self::assertSame(['name', 'category'], array_map(static fn (DoctrineField $field): string => $field->name, $fields));
        self::assertFalse($fields[0]->association);
        self::assertTrue($fields[1]->association);
        self::assertSame('App\Entity\Category', $fields[1]->targetEntity);
    }

    public function testScopesRepositoryCompletionToTheContainingMethod(): void
    {
        $extractor = $this->extractor();
        $text = <<<'PHP'
            <?php
            namespace App\Service;

            use App\Repository\CategoryRepository;
            use App\Repository\ProductRepository;

            final class Finder
            {
                public function products(ProductRepository $repository, CategoryRepository $categories): void
                {
                    $categories->findBy(['title' => 'Symfony']);
                    $repository->findBy(['name' => 'Symfony']);
                }

                public function categories(CategoryRepository $repository): void
                {
                }
            }
            PHP;

        $offset = strpos($text, "'name'") + \strlen("'na");
        $context = $extractor->completionContext('php', $text, $offset);

        self::assertSame('App\\Repository\\ProductRepository', $context?->repositoryClass);
    }

    public function testScopesThisRepositoryCompletionToTheContainingClass(): void
    {
        $extractor = $this->extractor();
        $text = <<<'PHP'
            <?php
            namespace App\Repository;

            use App\Entity\Category;
            use App\Entity\Product;
            use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
            use Doctrine\Persistence\ManagerRegistry;

            final class ProductRepository extends ServiceEntityRepository
            {
                public function __construct(ManagerRegistry $registry)
                {
                    parent::__construct($registry, Product::class);
                }
            }

            final class CategoryRepository extends ServiceEntityRepository
            {
                public function __construct(ManagerRegistry $registry)
                {
                    parent::__construct($registry, Category::class);
                }

                public function matching(): void
                {
                    $this->findBy(['title' => 'Symfony']);
                }
            }
            PHP;

        $offset = strpos($text, "'title'") + \strlen("'ti");
        $context = $extractor->completionContext('php', $text, $offset);

        self::assertSame('App\\Repository\\CategoryRepository', $context?->repositoryClass);
    }

    public function testIgnoresNamedArgumentsInPositionalDoctrineSlots(): void
    {
        $extractor = $this->extractor();
        $text = <<<'PHP'
            <?php
            use App\Entity\Product;
            use Symfony\Bridge\Doctrine\Form\Type\EntityType;

            $builder->add(unrelated: EntityType::class, ignored: null, options: [
                'class' => Product::class,
                'choice_label' => 'name',
            ]);
            $repository = $manager->getRepository(unrelated: Product::class);
            $repository->findBy(['name' => 'Symfony']);
            PHP;

        self::assertSame([], $extractor->extract(new SourceDocument('file:///workspace/src/Usage.php', 'php', $text))->symbols);
    }

    public function testExtractsConservativeDecodedLiteralArrayFacts(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->extractor($converter);
        $text = <<<'PHP'
            <?php
            namespace App\Service;

            use App\Entity\Product;
            use App\Repository\ProductRepository;
            use Symfony\Bridge\Doctrine\Form\Type\EntityType;

            function inspect(object $builder, ProductRepository $products, array $options, array $criteria, string $dynamic): void
            {
                $builder->add('product', EntityType::class, [
                    'metadata' => [']' => ', =>'],
                    "cl\x61ss" => Product::class,
                    'choice_label' => "na\x6de",
                    'choice_value' => 'id'.suffix(),
                    $dynamic => 'ignored',
                    ...$options,
                    'group_by' => 'category',
                ]);
                $products->findBy([
                    "na\x6de" => 'value, ] =>',
                    'relation' => ['nested' => true],
                    $dynamic => true,
                    ...$criteria,
                    'after' => true,
                ]);
            }
            PHP;

        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Service/Inspector.php', 'php', $text));
        $references = array_values(array_filter($facts->symbols, static fn ($symbol): bool => !$symbol->declaration));

        self::assertSame(
            [
                [DoctrineSymbolKind::Entity, 'App\\Entity\\Product'],
                [DoctrineSymbolKind::Field, 'name'],
                [DoctrineSymbolKind::Field, 'category'],
                [DoctrineSymbolKind::Field, 'name'],
                [DoctrineSymbolKind::Field, 'relation'],
                [DoctrineSymbolKind::Field, 'after'],
            ],
            array_map(static fn ($symbol): array => [$symbol->kind, $symbol->name], $references),
        );
        self::assertSame('na\\x6de', substr(
            $text,
            $converter->toByteOffset($text, $references[3]->range->start),
            $converter->toByteOffset($text, $references[3]->range->end) - $converter->toByteOffset($text, $references[3]->range->start),
        ));
    }

    public function testIgnoresCommentedDoctrinePhpWhilePreservingActiveRanges(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->extractor($converter);
        $text = <<<'PHP'
            <?php
            namespace App\Form;

            use App\Entity\Product;
            use App\Repository\ProductRepository;
            use Symfony\Bridge\Doctrine\Form\Type\EntityType;

            /*
            #[\Doctrine\ORM\Mapping\Entity]
            final class Ghost
            {
                #[\Doctrine\ORM\Mapping\Column]
                private string $ghost;
            }
            */

            function configure(object $builder, ProductRepository $products): void
            {
                // $builder->add('ghost', EntityType::class, ['class' => Ghost::class, 'choice_label' => 'commented_entity_type']);
                /* $products->findBy(['commented_criteria' => true]); */
                $builder->add('product', EntityType::class, ['class' => Product::class, 'choice_label' => 'active_entity_type']);
                $products->findBy(['active_criteria' => true]);
            }
            PHP;

        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Form/ProductType.php', 'php', $text));
        self::assertSame([], $facts->entities);

        $references = array_values(array_filter($facts->symbols, static fn ($symbol): bool => !$symbol->declaration));
        foreach ($references as $reference) {
            self::assertStringNotContainsString('commented_', $reference->name);
            self::assertNotSame('App\\Entity\\Ghost', $reference->name);
        }

        $entityReferences = array_values(array_filter($references, static fn ($symbol): bool => DoctrineSymbolKind::Entity === $symbol->kind));
        self::assertCount(1, $entityReferences);
        self::assertSame('App\\Entity\\Product', $entityReferences[0]->name);
        self::assertSame(strpos($text, 'Product::class'), $converter->toByteOffset($text, $entityReferences[0]->range->start));

        $fieldReferences = array_values(array_filter($references, static fn ($symbol): bool => DoctrineSymbolKind::Field === $symbol->kind));
        $fieldNames = [];
        foreach ($fieldReferences as $fieldReference) {
            $fieldNames[] = $fieldReference->name;
        }
        self::assertSame(['active_entity_type', 'active_criteria'], $fieldNames);
        self::assertSame(strpos($text, 'active_entity_type'), $converter->toByteOffset($text, $fieldReferences[0]->range->start));
        self::assertSame(strpos($text, 'active_criteria'), $converter->toByteOffset($text, $fieldReferences[1]->range->start));
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function params(PositionConverter $converter, string $uri, string $text, int $offset): array
    {
        $position = $converter->toPosition($text, $offset);

        return [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ];
    }

    public function testNavigatesToRuntimeOnlyEntities(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->extractor($converter);
        $project = new Project('/workspace', 'file:///workspace');
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $indexes = new DoctrineIndexRegistry();
        $entityUri = 'file:///workspace/vendor/acme/entity/Book.php';
        $range = new Range(new Position(0, 0), new Position(0, 0));
        $indexes->forProject($project)->replaceRuntime(new DoctrineEntity(
            'Acme\Entity\Book',
            $entityUri,
            $range,
            null,
            [new DoctrineField('title', $entityUri, $range, false, 'string')],
        ));
        $usageUri = 'file:///workspace/src/Finder.php';
        $usageText = <<<'PHP'
            <?php
            use Acme\Entity\Book;
            $repository = $manager->getRepository(Book::class);
            $repository->findBy(['title' => 'Symfony']);
            PHP;
        $documents = new DocumentStore();
        $documents->open(new Document($usageUri, 'php', 1, $usageText));
        $provider = new DoctrineRelationshipProvider(new DocumentContextResolver($documents, $projects), new PositionedSourceSymbolResolver($converter), new LspProtocolMapper(), $indexes, $extractor);

        $params = $this->params($converter, $usageUri, $usageText, strpos($usageText, "['title'") + 3);
        self::assertSame([$entityUri], array_column($provider->definition($params) ?? [], 'uri'));
        $hover = $provider->hover($params);
        self::assertIsArray($hover);
        self::assertIsArray($hover['contents'] ?? null);
        self::assertIsString($hover['contents']['value'] ?? null);
        self::assertStringContainsString('Doctrine field: `Acme\Entity\Book::$title`', $hover['contents']['value']);
    }

    public function testOffersNoDoctrineCompletionsInsidePhpComments(): void
    {
        $extractor = $this->extractor();
        $text = <<<'PHP'
            <?php
            use App\Repository\ProductRepository;
            function find(ProductRepository $products): void {
                // $products->findBy(['na
            }
            PHP;

        self::assertNull($extractor->completionContext('php', $text, strpos($text, "['na") + \strlen("['na")));
    }

    private function extractor(?PositionConverter $converter = null): DoctrineExtractor
    {
        return new DoctrineExtractor(
            $converter ?? new PositionConverter(),
            new TolerantPhpParser(new Parser()),
            new PhpCommentParser(),
            new DoctrineRepositoryReceiverResolver(new PhpCapturedReceiverResolver(new BalancedDelimiterMatcher())),
            new PhpLiteralArrayKeyParser(),
        );
    }
}
