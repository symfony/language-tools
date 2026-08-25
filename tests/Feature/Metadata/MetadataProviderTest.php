<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\Metadata\FormMetadataProvider;
use Symfony\Lsp\Feature\Metadata\FormType;
use Symfony\Lsp\Feature\Metadata\MetadataExtractor;
use Symfony\Lsp\Feature\Metadata\MetadataIndexRegistry;
use Symfony\Lsp\Feature\Metadata\MetadataRelationshipProvider;
use Symfony\Lsp\Feature\Metadata\MetadataSourceIndexRegistry;
use Symfony\Lsp\Feature\Metadata\SerializerMetadataProvider;
use Symfony\Lsp\Feature\Metadata\ValidationConstraint;
use Symfony\Lsp\Feature\Metadata\ValidationMetadataProvider;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class MetadataProviderTest extends TestCase
{
    public function testProvidesFormConstraintSerializerAndMappingMetadata(): void
    {
        $converter = new PositionConverter();
        $extractor = new MetadataExtractor($converter, new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))), new TolerantPhpParser(new Parser()), new PhpCommentParser());
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $indexes = new MetadataIndexRegistry();
        $indexes->forProject($project)->replace(
            [new FormType('App\\Form\\EventType', 'event', ['action', 'required'], ['required'])],
            [
                new ValidationConstraint('Choice', 'Symfony\\Component\\Validator\\Constraints\\Choice', ['choices', 'groups']),
                new ValidationConstraint('Ip', 'Symfony\\Component\\Validator\\Constraints\\Ip', ['version']),
                new ValidationConstraint('Language', 'Symfony\\Component\\Validator\\Constraints\\Language', ['language']),
                new ValidationConstraint('Length', 'Symfony\\Component\\Validator\\Constraints\\Length', ['groups', 'max', 'message', 'min']),
                new ValidationConstraint('Type', 'Symfony\\Component\\Validator\\Constraints\\Type', ['groups', 'type']),
                new ValidationConstraint('When', 'Symfony\\Component\\Validator\\Constraints\\When', ['constraints', 'expression']),
            ],
            true,
            true,
        );
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
        $protocol = new LspProtocolMapper();
        $formProvider = new FormMetadataProvider($resolver, $converter, $protocol, $indexes, $extractor);
        $validationProvider = new ValidationMetadataProvider($resolver, $converter, $protocol, $indexes, $sourceIndexes, $extractor);
        $serializerProvider = new SerializerMetadataProvider($resolver, $converter, $protocol, $sourceIndexes, $extractor);
        $relationshipProvider = new MetadataRelationshipProvider($resolver, $converter, $protocol, $sourceIndexes, $extractor);
        $completionProviders = [$formProvider, $validationProvider, $serializerProvider, $relationshipProvider];
        $hoverProviders = [$relationshipProvider, $formProvider, $validationProvider];
        $diagnosticProviders = [$formProvider, $validationProvider];

        $formUri = 'file:///workspace/src/Controller/EventController.php';
        $formText = <<<'PHP'
            <?php
            namespace App\Controller;
            use App\Form\{EventType};
            use Symfony\Component\Form\{FormBuilderInterface};
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
        self::assertSame(['required'], $this->completionLabels($completionProviders, $converter, $formUri, $formText, $firstRequired + 4));
        $builderRequired = strpos($formText, 'required', $firstRequired + 1);
        self::assertSame(['required'], $this->completionLabels($completionProviders, $converter, $formUri, $formText, $builderRequired + 4));
        self::assertSame(['form.unknown_option'], array_column($this->diagnostics($diagnosticProviders, $formUri), 'code'));
        $required = strpos($formText, 'required') + 1;
        self::assertIsArray($this->hover($hoverProviders, $converter, $formUri, $formText, $required));

        $constraintUri = 'file:///workspace/src/Dto/Input.php';
        $constraintText = <<<'PHP'
            <?php
            namespace App\Dto;
            use Symfony\Component\DependencyInjection\Attribute as Assert;
            use Symfony\Component\Validator\Constraints as Validation;
            final class Input
            {
                #[Assert\When(env: 'dev', exp)]
                #[Validation\When(exp)]
                #[Validation\Length(ma)]
                #[Validation\Length(unknown: 1)]
                #[Validation\Ip(version: Validation\Ip::ALL)]
                #[Validation\Choice(choices: [MapMarkerType::Community->value, MapMarkerType::Ecosystem->value], groups: ['Submit'])]
                #[Validation\Choice(MapMarkerType::cases())]
                #[Validation\Type(\DateTimeInterface::class, groups: ['Submit'])]
                public string $value;
            }
            PHP;
        $documents->open(new Document($constraintUri, 'php', 1, $constraintText));
        $dependencyInjectionWhen = strpos($constraintText, 'exp)]');
        self::assertIsInt($dependencyInjectionWhen);
        self::assertSame([], $this->completionLabels($completionProviders, $converter, $constraintUri, $constraintText, $dependencyInjectionWhen + 3));
        $validatorWhen = strpos($constraintText, 'exp)]', $dependencyInjectionWhen + 1);
        self::assertIsInt($validatorWhen);
        self::assertSame(['expression'], $this->completionLabels($completionProviders, $converter, $constraintUri, $constraintText, $validatorWhen + 3));
        self::assertSame(['max'], $this->completionLabels($completionProviders, $converter, $constraintUri, $constraintText, strpos($constraintText, 'ma)') + 2));
        self::assertNull($this->hover($hoverProviders, $converter, $constraintUri, $constraintText, strpos($constraintText, 'Assert\\When') + \strlen('Assert\\')));
        self::assertNull($this->hover($hoverProviders, $converter, $constraintUri, $constraintText, strpos($constraintText, 'env:') + 1));
        self::assertIsArray($this->hover($hoverProviders, $converter, $constraintUri, $constraintText, strpos($constraintText, 'unknown:') + 1));
        $diagnostics = $this->diagnostics($diagnosticProviders, $constraintUri);
        self::assertSame(['validation.unknown_constraint_option'], array_column($diagnostics, 'code'));
        self::assertSame('Unknown option "unknown" for constraint "Length".', $diagnostics[0]['message'] ?? null);

        $directConstraintUri = 'file:///workspace/src/Dto/DirectInput.php';
        $directConstraintText = <<<'PHP'
            <?php
            namespace App\Dto;
            use Symfony\Component\Validator\Constraints\Length;
            #[Len
            PHP;
        $documents->open(new Document($directConstraintUri, 'php', 1, $directConstraintText));
        self::assertSame(['Length'], $this->completionLabels($completionProviders, $converter, $directConstraintUri, $directConstraintText, \strlen($directConstraintText)));

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
        self::assertSame(['max'], $this->completionLabels($completionProviders, $converter, $validationUri, $validationText, strpos($validationText, 'max:') + 3));
        self::assertSame(['validation.unknown_constraint_option'], array_column($this->diagnostics($diagnosticProviders, $validationUri), 'code'));
        $constraintNameUri = 'file:///workspace/config/validator/Custom.yaml';
        $constraintNameText = "App\\Entity\\User:\n    properties:\n        email:\n            - Sl";
        $documents->open(new Document($constraintNameUri, 'yaml', 1, $constraintNameText));
        self::assertSame(['Slug'], $this->completionLabels($completionProviders, $converter, $constraintNameUri, $constraintNameText, \strlen($constraintNameText)));

        $groupUri = 'file:///workspace/src/Serializer.php';
        $groupText = "<?php\n\$context = ['groups' => ['ad";
        $documents->open(new Document($groupUri, 'php', 1, $groupText));
        self::assertSame(['admin'], $this->completionLabels($completionProviders, $converter, $groupUri, $groupText, \strlen($groupText)));

        $propertyUri = 'file:///workspace/config/serializer/Completion.yaml';
        $propertyText = "App\\Entity\\User:\n    attributes:\n        em";
        $documents->open(new Document($propertyUri, 'yaml', 1, $propertyText));
        self::assertSame(['email'], $this->completionLabels($completionProviders, $converter, $propertyUri, $propertyText, \strlen($propertyText)));

        $mappedClass = strpos($mappingText, 'App\\Entity\\User') + 1;
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

    /**
     * @param list<CompletionProviderInterface> $providers
     *
     * @return list<string>
     */
    private function completionLabels(array $providers, PositionConverter $converter, string $uri, string $text, int $offset): array
    {
        $items = [];
        foreach ($providers as $provider) {
            $completion = $provider->complete($this->params($converter, $uri, $text, $offset));
            if (null !== $completion) {
                array_push($items, ...$completion);
            }
        }
        /** @var list<string> $labels */
        $labels = array_column($items, 'label');

        return $labels;
    }

    /**
     * @param list<HoverProviderInterface> $providers
     *
     * @return array<array-key, mixed>|null
     */
    private function hover(array $providers, PositionConverter $converter, string $uri, string $text, int $offset): ?array
    {
        foreach ($providers as $provider) {
            if (null !== $hover = $provider->hover($this->params($converter, $uri, $text, $offset))) {
                return $hover;
            }
        }

        return null;
    }

    /**
     * @param list<DiagnosticProviderInterface> $providers
     *
     * @return list<array<array-key, mixed>>
     */
    private function diagnostics(array $providers, string $uri): array
    {
        $diagnostics = [];
        foreach ($providers as $provider) {
            $provided = $provider->diagnostics(['textDocument' => ['uri' => $uri]]);
            if (null !== $provided) {
                array_push($diagnostics, ...$provided);
            }
        }

        return $diagnostics;
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function params(PositionConverter $converter, string $uri, string $text, int $offset): array
    {
        $position = $converter->toPosition($text, $offset);

        return ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]];
    }

    public function testOffersNoMetadataCompletionsInsidePhpComments(): void
    {
        $converter = new PositionConverter();
        $extractor = new MetadataExtractor($converter, new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))), new TolerantPhpParser(new Parser()), new PhpCommentParser());
        $text = "<?php // #[Groups(['adm";

        self::assertNull($extractor->completionContext('php', $text, \strlen($text)));
    }
}
