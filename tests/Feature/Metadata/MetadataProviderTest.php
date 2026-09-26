<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use Symfony\Lsp\Feature\Metadata\MetadataRelationshipProvider;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class MetadataProviderTest extends MetadataTestCase
{
    public function testIntegratesPhpDeclarationsWithYamlReferencesAcrossDomains(): void
    {
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
        $kit = (new ProjectTestKit())
            ->open($entityUri, $entityText)
            ->open($constraintDeclarationUri, $constraintDeclarationText)
            ->open($mappingUri, $mappingText)
            ->index()
        ;
        $relationshipProvider = $kit->get(MetadataRelationshipProvider::class);

        $mappedClass = strpos($mappingText, 'App\Entity\User') + 1;
        $classDefinition = $relationshipProvider->definition($kit->positioned($kit->offset($mappingUri, $mappedClass)));
        self::assertSame([$entityUri], $kit->targets($classDefinition));
        $email = strpos($mappingText, 'email') + 1;
        $definition = $relationshipProvider->definition($kit->positioned($kit->offset($mappingUri, $email)));
        self::assertSame([$entityUri], $kit->targets($definition));
        $admin = strpos($mappingText, 'admin') + 1;
        $references = $relationshipProvider->references($kit->references($kit->offset($mappingUri, $admin)));
        self::assertCount(2, $references);
    }
}
