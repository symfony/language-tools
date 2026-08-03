<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Feature\Metadata\FormType;
use Symfony\Lsp\Feature\Metadata\MetadataExtractor;
use Symfony\Lsp\Feature\Metadata\MetadataIndexRegistry;
use Symfony\Lsp\Feature\Metadata\MetadataProvider;
use Symfony\Lsp\Feature\Metadata\MetadataSourceIndexRegistry;
use Symfony\Lsp\Feature\Metadata\ValidationConstraint;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class MetadataProviderTest extends TestCase
{
    public function testProvidesFormConstraintSerializerAndMappingMetadata(): void
    {
        $converter = new PositionConverter();
        $extractor = new MetadataExtractor($converter, new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))));
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $indexes = new MetadataIndexRegistry();
        $indexes->forProject($project)->replace(
            [new FormType('App\\Form\\EventType', 'event', ['action', 'required'], ['required'])],
            [new ValidationConstraint('Length', 'Symfony\\Component\\Validator\\Constraints\\Length', ['groups', 'max', 'message', 'min'])],
            true,
            true,
        );
        $entityUri = 'file:///workspace/src/Entity/User.php';
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
        $constraintDeclarationUri = 'file:///workspace/src/Validator/Slug.php';
        $constraintDeclarationText = <<<'PHP'
            <?php
            namespace App\Validator;
            use Symfony\Component\Validator\Constraint;
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
        $provider = new MetadataProvider(new DocumentContextResolver($documents, $projects), $documents, $projects, $converter, $indexes, $sourceIndexes, $extractor);

        $formUri = 'file:///workspace/src/Controller/EventController.php';
        $formText = <<<'PHP'
            <?php
            namespace App\Controller;
            use App\Form\EventType;
            use Symfony\Component\Form\FormBuilderInterface;
            final class EventController
            {
                public function create(): void
                {
                    $this->createForm(EventType::class, null, ['required' => true, 'bogus' => true]);
                }

                public function build(FormBuilderInterface $builder, object $menu): void
                {
                    $builder->add('title', EventType::class, ['required' => true]);
                    $menu->add('title', EventType::class, ['bogus' => true]);
                }
            }
            PHP;
        $documents->open(new Document($formUri, 'php', 1, $formText));
        $firstRequired = strpos($formText, 'required');
        self::assertSame(['required'], $this->completionLabels($provider, $converter, $formUri, $formText, $firstRequired + 4));
        $builderRequired = strpos($formText, 'required', $firstRequired + 1);
        self::assertSame(['required'], $this->completionLabels($provider, $converter, $formUri, $formText, $builderRequired + 4));
        $formDiagnostics = $provider->diagnostics(['textDocument' => ['uri' => $formUri]]);
        self::assertIsArray($formDiagnostics);
        self::assertSame(['form.unknown_option'], array_column($formDiagnostics, 'code'));
        $required = strpos($formText, 'required') + 1;
        self::assertNotNull($this->hover($provider, $converter, $formUri, $formText, $required));

        $constraintUri = 'file:///workspace/src/Dto/Input.php';
        $constraintText = <<<'PHP'
            <?php
            final class Input
            {
                #[Assert\Length(ma)]
                #[Assert\Length(unknown: 1)]
                public string $value;
            }
            PHP;
        $documents->open(new Document($constraintUri, 'php', 1, $constraintText));
        self::assertSame(['max'], $this->completionLabels($provider, $converter, $constraintUri, $constraintText, strpos($constraintText, 'ma)') + 2));
        $constraintDiagnostics = $provider->diagnostics(['textDocument' => ['uri' => $constraintUri]]);
        self::assertIsArray($constraintDiagnostics);
        self::assertSame(['validation.unknown_constraint_option'], array_column($constraintDiagnostics, 'code'));
        $validationUri = 'file:///workspace/config/validator/User.yaml';
        $validationText = <<<'YAML'
            App\Entity\User:
                properties:
                    email:
                        - Length:
                            max: 120
                            maximum: 200
            YAML;
        $documents->open(new Document($validationUri, 'yaml', 1, $validationText));
        self::assertSame(['max'], $this->completionLabels($provider, $converter, $validationUri, $validationText, strpos($validationText, 'max:') + 3));
        $yamlDiagnostics = $provider->diagnostics(['textDocument' => ['uri' => $validationUri]]);
        self::assertIsArray($yamlDiagnostics);
        self::assertSame(['validation.unknown_constraint_option'], array_column($yamlDiagnostics, 'code'));
        $constraintNameUri = 'file:///workspace/config/validator/Custom.yaml';
        $constraintNameText = "App\\Entity\\User:\n    properties:\n        email:\n            - Sl";
        $documents->open(new Document($constraintNameUri, 'yaml', 1, $constraintNameText));
        self::assertSame(['Slug'], $this->completionLabels($provider, $converter, $constraintNameUri, $constraintNameText, \strlen($constraintNameText)));

        $groupUri = 'file:///workspace/src/Serializer.php';
        $groupText = "<?php\n\$context = ['groups' => ['ad";
        $documents->open(new Document($groupUri, 'php', 1, $groupText));
        self::assertSame(['admin'], $this->completionLabels($provider, $converter, $groupUri, $groupText, \strlen($groupText)));

        $propertyUri = 'file:///workspace/config/serializer/Completion.yaml';
        $propertyText = "App\\Entity\\User:\n    attributes:\n        em";
        $documents->open(new Document($propertyUri, 'yaml', 1, $propertyText));
        self::assertSame(['email'], $this->completionLabels($provider, $converter, $propertyUri, $propertyText, \strlen($propertyText)));

        $mappedClass = strpos($mappingText, 'App\\Entity\\User') + 1;
        $classDefinition = $provider->definition($this->params($converter, $mappingUri, $mappingText, $mappedClass));
        self::assertIsArray($classDefinition);
        self::assertSame([$entityUri], array_column($classDefinition, 'uri'));
        $email = strpos($mappingText, 'email') + 1;
        $definition = $provider->definition($this->params($converter, $mappingUri, $mappingText, $email));
        self::assertIsArray($definition);
        self::assertSame([$entityUri], array_column($definition, 'uri'));
        $admin = strpos($mappingText, 'admin') + 1;
        $references = $provider->references($this->params($converter, $mappingUri, $mappingText, $admin));
        self::assertIsArray($references);
        self::assertCount(2, $references);
    }

    /** @return list<string> */
    private function completionLabels(MetadataProvider $provider, PositionConverter $converter, string $uri, string $text, int $offset): array
    {
        /** @var list<string> $labels */
        $labels = array_column($provider->complete($this->params($converter, $uri, $text, $offset)) ?? [], 'label');

        return $labels;
    }

    /** @return array<array-key, mixed>|null */
    private function hover(MetadataProvider $provider, PositionConverter $converter, string $uri, string $text, int $offset): ?array
    {
        return $provider->hover($this->params($converter, $uri, $text, $offset));
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function params(PositionConverter $converter, string $uri, string $text, int $offset): array
    {
        $position = $converter->toPosition($text, $offset);

        return ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]];
    }
}
