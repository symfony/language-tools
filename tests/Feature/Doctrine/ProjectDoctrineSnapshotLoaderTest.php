<?php

namespace Symfony\Lsp\Tests\Feature\Doctrine;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Doctrine\DoctrineIndexRegistry;
use Symfony\Lsp\Feature\Doctrine\ProjectDoctrineSnapshotLoader;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class ProjectDoctrineSnapshotLoaderTest extends TestCase
{
    public function testLoadsRuntimeEntities(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $indexes = new DoctrineIndexRegistry();
        $loader = new ProjectDoctrineSnapshotLoader($indexes, new ContainerPathMapper(new RuntimeConfiguration()), new UriToPathConverter());
        $loader->load($project, [
            'complete' => true,
            'entities' => [
                [
                    'className' => 'App\Entity\Book',
                    'file' => '/workspace/src/Entity/Book.php',
                    'repositoryClass' => 'App\Repository\BookRepository',
                    'fields' => [
                        ['name' => 'title', 'type' => 'string', 'association' => false, 'targetEntity' => null],
                        ['name' => 'author', 'type' => null, 'association' => true, 'targetEntity' => 'App\Entity\Author'],
                    ],
                ],
                ['className' => 'App\Entity\Broken', 'file' => null, 'fields' => []],
            ],
        ]);

        $index = $indexes->forProject($project);
        $entity = $index->entity('App\Entity\Book');
        self::assertNotNull($entity);
        self::assertSame('file:///workspace/src/Entity/Book.php', $entity->uri);
        self::assertSame('App\Repository\BookRepository', $entity->repositoryClass);
        self::assertSame('string', $entity->field('title')?->type);
        $author = $entity->field('author');
        self::assertNotNull($author);
        self::assertTrue($author->association);
        self::assertSame('App\Entity\Author', $author->targetEntity);
        self::assertNull($index->entity('App\Entity\Broken'));
        self::assertSame(['App\Entity\Book'], array_map(static fn ($item): string => $item->className, $index->entities()));
    }
}
