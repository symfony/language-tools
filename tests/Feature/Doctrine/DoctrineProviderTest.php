<?php

namespace Symfony\Lsp\Tests\Feature\Doctrine;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Doctrine\DoctrineExtractor;
use Symfony\Lsp\Feature\Doctrine\DoctrineIndexRegistry;
use Symfony\Lsp\Feature\Doctrine\DoctrineProvider;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class DoctrineProviderTest extends TestCase
{
    public function testCompletesAndNavigatesEntityFieldsAndRepositoryMappings(): void
    {
        $entityUri = 'file:///workspace/src/Entity/Product.php';
        $entityText = <<<'PHP'
            <?php
            namespace App\Entity;

            use App\Repository\ProductRepository;
            use Doctrine\ORM\Mapping as ORM;

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

            use App\Entity\Product;
            use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
            use Doctrine\Persistence\ManagerRegistry;

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

            use App\Entity\Product;
            use App\Repository\ProductRepository;
            use Doctrine\ORM\EntityManagerInterface;
            use Symfony\Bridge\Doctrine\Form\Type\EntityType;
            use Symfony\Component\Form\FormBuilderInterface;

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
            use App\Entity\Product;
            use Symfony\Bridge\Doctrine\Form\Type\EntityType;
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
        $extractor = new DoctrineExtractor($converter);
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $indexes = new DoctrineIndexRegistry();
        $index = $indexes->forProject($project);
        $index->replace(
            $extractor->extract($entityUri, 'php', $entityText),
            $extractor->extract($repositoryUri, 'php', $repositoryText),
            $extractor->extract($usageUri, 'php', $usageText),
        );
        self::assertSame(['id', 'name', 'category'], array_map(static fn ($field): string => $field->name(), $index->entity('App\\Entity\\Product')?->fields() ?? []));
        self::assertSame('App\\Entity\\Product', $index->repository('App\\Repository\\ProductRepository')?->entityClass());

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
        $provider = new DoctrineProvider(new DocumentContextResolver($documents, $projects), $documents, $projects, $converter, $indexes, $extractor);

        self::assertSame(['name'], array_column($provider->complete($this->params($converter, $formCompletionUri, $formCompletionText, \strlen($formCompletionText))) ?? [], 'label'));
        self::assertSame(['name'], array_column($provider->complete($this->params($converter, $repositoryCompletionUri, $repositoryCompletionText, \strlen($repositoryCompletionText))) ?? [], 'label'));
        self::assertSame(['category'], array_column($provider->complete($this->params($converter, $managerCompletionUri, $managerCompletionText, \strlen($managerCompletionText))) ?? [], 'label'));

        $fieldParams = $this->params($converter, $usageUri, $usageText, strpos($usageText, "['name'") + 3);
        self::assertSame([$entityUri], array_column($provider->definition($fieldParams) ?? [], 'uri'));
        self::assertCount(3, $provider->references($fieldParams) ?? []);
        $fieldHover = $provider->hover($fieldParams);
        self::assertIsArray($fieldHover);
        self::assertIsArray($fieldHover['contents'] ?? null);
        self::assertIsString($fieldHover['contents']['value'] ?? null);
        self::assertStringContainsString('Doctrine field: `App\\Entity\\Product::$name`', $fieldHover['contents']['value']);

        $repositoryParams = $this->params($converter, $entityUri, $entityText, strpos($entityText, 'ProductRepository::class') + 2);
        self::assertSame([$repositoryUri], array_column($provider->definition($repositoryParams) ?? [], 'uri'));
        $entityParams = $this->params($converter, $repositoryUri, $repositoryText, strpos($repositoryText, 'Product::class') + 2);
        self::assertSame([$entityUri], array_column($provider->definition($entityParams) ?? [], 'uri'));

        $entityLenses = $provider->codeLenses(['textDocument' => ['uri' => $entityUri]]);
        self::assertIsArray($entityLenses);
        self::assertIsArray($entityLenses[0]['command'] ?? null);
        self::assertSame('Repository: App\\Repository\\ProductRepository', $entityLenses[0]['command']['title'] ?? null);
        $repositoryLenses = $provider->codeLenses(['textDocument' => ['uri' => $repositoryUri]]);
        self::assertIsArray($repositoryLenses);
        self::assertIsArray($repositoryLenses[0]['command'] ?? null);
        self::assertSame('Entity: App\\Entity\\Product', $repositoryLenses[0]['command']['title'] ?? null);
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function params(PositionConverter $converter, string $uri, string $text, int $offset): array
    {
        $position = $converter->toPosition($text, $offset);

        return [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ];
    }
}
