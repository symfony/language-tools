<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class DoctrineFixtureBuilder
{
    public function __construct(
        private readonly BridgeFixtureWorkspace $workspace,
        private readonly FakeFrameworkPrelude $prelude = new FakeFrameworkPrelude(),
    ) {
    }

    public function writeDoctrineApplication(): void
    {
        $this->workspace->makeDirectory('src/Entity');
        $this->workspace->write('src/Entity/Book.php', <<<'PHP'
            <?php
            namespace App\Entity;
            class Book
            {
            }
            PHP);
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            namespace Doctrine\Persistence;
            interface ManagerRegistry
            {
            }
            namespace Doctrine\ORM\Mapping;
            class ClassMetadata
            {
                public $customRepositoryClassName = 'App\Repository\BookRepository';
                public function getName(): string { return 'App\Entity\Book'; }
                public function getReflectionClass(): \ReflectionClass
                {
                    require_once \dirname(__DIR__).'/src/Entity/Book.php';
                    return new \ReflectionClass('App\Entity\Book');
                }
                public function getFieldNames(): array { return ['title']; }
                public function getTypeOfField(string $name): string { return 'string'; }
                public function getAssociationNames(): array { return ['author']; }
                public function getAssociationTargetClass(string $name): string { return 'App\Entity\Author'; }
            }
            namespace App;
            final class MetadataFactory
            {
                public function getAllMetadata(): array { return [new \Doctrine\ORM\Mapping\ClassMetadata()]; }
            }
            final class Manager
            {
                public function getMetadataFactory(): MetadataFactory { return new MetadataFactory(); }
            }
            final class Registry implements \Doctrine\Persistence\ManagerRegistry
            {
                public function getManagers(): array { return ['default' => new Manager()]; }
            }
            final class Container
            {
                public function has(string $id): bool { return 'doctrine' === $id; }
                public function get(string $id): object { return new Registry(); }
            }
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function boot(): void {}
                public function shutdown(): void {}
                public function getContainer(): Container { return new Container(); }
            }
            PHP,
        ));
    }
}
