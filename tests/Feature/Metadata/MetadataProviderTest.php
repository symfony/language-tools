<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Metadata\MetadataRelationshipProvider;
use Symfony\Lsp\Feature\Metadata\MetadataSourceIndexRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class MetadataProviderTest extends MetadataTestCase
{
    public function testIntegratesPhpDeclarationsWithYamlReferencesAcrossDomains(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $entityUri = 'file:///workspace/src/Entity/User.php';
        $entityText = <<<'PHP'
            <?php
            namespace App\Entity;
            use Symfony\Component\Serializer\Attribute\{Groups};
            final class User
            {
                #[Groups(['admin'])]
                public string $email;
            }
            PHP;
        $constraintDeclarationUri = 'file:///workspace/src/Validator/Slug.php';
        $constraintDeclarationText = <<<'PHP'
            <?php
            namespace App\Validator;
            use Symfony\Component\Validator\{Constraint};
            final class Slug extends Constraint
            {
            }
            PHP;
        $mappingUri = 'file:///workspace/config/serializer/User.yaml';
        $mappingText = <<<'YAML'
            App\Entity\User:
                attributes:
                    email:
                        groups: [admin]
            YAML;
        $sourceIndexes = new MetadataSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace(
            $extractor->extract($entityUri, 'php', $entityText),
            $extractor->extract($constraintDeclarationUri, 'php', $constraintDeclarationText),
            $extractor->extract($mappingUri, 'yaml', $mappingText),
        );
        $documents = new DocumentStore();
        $documents->open(new Document($entityUri, 'php', 1, $entityText));
        $documents->open(new Document($mappingUri, 'yaml', 1, $mappingText));
        $resolver = new DocumentContextResolver($documents, $projects);
        $relationshipProvider = new MetadataRelationshipProvider($resolver, $converter, new LspProtocolMapper(), $sourceIndexes, $extractor);

        $mappedClass = strpos($mappingText, 'App\Entity\User') + 1;
        $classDefinition = $relationshipProvider->definition($this->params($converter, $mappingUri, $mappingText, $mappedClass));
        self::assertIsArray($classDefinition);
        self::assertSame([$entityUri], array_column($classDefinition, 'uri'));
        $email = strpos($mappingText, 'email') + 1;
        $definition = $relationshipProvider->definition($this->params($converter, $mappingUri, $mappingText, $email));
        self::assertIsArray($definition);
        self::assertSame([$entityUri], array_column($definition, 'uri'));
        $admin = strpos($mappingText, 'admin') + 1;
        $references = $relationshipProvider->references($this->params($converter, $mappingUri, $mappingText, $admin));
        self::assertIsArray($references);
        self::assertCount(2, $references);
    }
}
