<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Metadata\MetadataCompletionProvider;
use Symfony\Lsp\Feature\Metadata\MetadataIndexRegistry;
use Symfony\Lsp\Feature\Metadata\MetadataSourceIndexRegistry;
use Symfony\Lsp\Feature\Metadata\MetadataSymbolKind;
use Symfony\Lsp\Feature\Metadata\ValidationConstraint;
use Symfony\Lsp\Feature\Metadata\ValidationMetadataProvider;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class ValidationMetadataProviderTest extends MetadataTestCase
{
    public function testProvidesPhpValidationMetadata(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $indexes = $this->createIndexes($project);
        $sourceIndexes = new MetadataSourceIndexRegistry();
        $documents = new DocumentStore();
        $resolver = new DocumentContextResolver($documents, $projects);
        $protocol = new LspProtocolMapper();
        $completionProvider = new MetadataCompletionProvider($resolver, $converter, $protocol, $indexes, $sourceIndexes, $extractor);
        $validationProvider = new ValidationMetadataProvider($resolver, $converter, $protocol, $indexes, $extractor);
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
                #[Validation\When(expression: 'true', constraints: [new Validation\NotBlank(message: 'm')], unexpected: 1)]
                public string $value;
            }
            PHP;
        $documents->open(new Document($constraintUri, 'php', 1, $constraintText));

        $dependencyInjectionWhen = strpos($constraintText, 'exp)]');
        self::assertIsInt($dependencyInjectionWhen);
        self::assertSame([], $this->completionLabels($completionProvider, $converter, $constraintUri, $constraintText, $dependencyInjectionWhen + 3));
        $validatorWhen = strpos($constraintText, 'exp)]', $dependencyInjectionWhen + 1);
        self::assertIsInt($validatorWhen);
        self::assertSame(['expression'], $this->completionLabels($completionProvider, $converter, $constraintUri, $constraintText, $validatorWhen + 3));
        self::assertSame(['max'], $this->completionLabels($completionProvider, $converter, $constraintUri, $constraintText, strpos($constraintText, 'ma)') + 2));
        self::assertNull($this->hover([$validationProvider], $converter, $constraintUri, $constraintText, strpos($constraintText, 'Assert\When') + \strlen('Assert\\')));
        self::assertNull($this->hover([$validationProvider], $converter, $constraintUri, $constraintText, strpos($constraintText, 'env:') + 1));
        self::assertIsArray($this->hover([$validationProvider], $converter, $constraintUri, $constraintText, strpos($constraintText, 'unknown:') + 1));
        self::assertIsArray($this->hover([$validationProvider], $converter, $constraintUri, $constraintText, strpos($constraintText, "expression: 'true'") + 1));
        $diagnostics = $this->diagnostics([$validationProvider], $constraintUri);
        self::assertSame(['validation.unknown_constraint_option', 'validation.unknown_constraint_option'], array_column($diagnostics, 'code'));
        self::assertSame('Unknown option "unknown" for constraint "Length".', $diagnostics[0]['message'] ?? null);
        self::assertSame('Unknown option "unexpected" for constraint "When".', $diagnostics[1]['message'] ?? null);

        $directConstraintUri = 'file:///workspace/src/Dto/DirectInput.php';
        $directConstraintText = <<<'PHP'
            <?php
            namespace App\Dto;
            use Symfony\Component\Validator\Constraints\Language;
            use Symfony\Component\Validator\Constraints\Length;
            #[L
            PHP;
        $documents->open(new Document($directConstraintUri, 'php', 1, $directConstraintText));
        self::assertSame(['Language', 'Length'], $this->completionLabels($completionProvider, $converter, $directConstraintUri, $directConstraintText, \strlen($directConstraintText)));

        $aliasedConstraintUri = 'file:///workspace/src/Dto/AliasedInput.php';
        $aliasedConstraintText = <<<'PHP'
            <?php
            namespace App\Dto;
            use Symfony\Component\Validator\Constraints\Length as AssertLength;
            #[AssertL
            PHP;
        $documents->open(new Document($aliasedConstraintUri, 'php', 1, $aliasedConstraintText));
        self::assertSame(['AssertLength'], $this->completionLabels($completionProvider, $converter, $aliasedConstraintUri, $aliasedConstraintText, \strlen($aliasedConstraintText)));
    }

    public function testProvidesYamlValidationMetadata(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $indexes = $this->createIndexes($project);
        $constraintDeclarationText = <<<'PHP'
            <?php
            namespace App\Validator;
            use Symfony\Component\Validator\Constraint;
            final class Slug extends Constraint
            {
            }
            PHP;
        $sourceIndexes = new MetadataSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace($extractor->extract('file:///workspace/src/Validator/Slug.php', 'php', $constraintDeclarationText));
        $documents = new DocumentStore();
        $resolver = new DocumentContextResolver($documents, $projects);
        $protocol = new LspProtocolMapper();
        $completionProvider = new MetadataCompletionProvider($resolver, $converter, $protocol, $indexes, $sourceIndexes, $extractor);
        $validationProvider = new ValidationMetadataProvider($resolver, $converter, $protocol, $indexes, $extractor);
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

        self::assertSame(['max'], $this->completionLabels($completionProvider, $converter, $validationUri, $validationText, strpos($validationText, 'max:') + 3));
        self::assertSame(['validation.unknown_constraint_option'], array_column($this->diagnostics([$validationProvider], $validationUri), 'code'));
        $constraintNameUri = 'file:///workspace/config/validator/Custom.yaml';
        $constraintNameText = "App\\Entity\\User:\n    properties:\n        email:\n            - Sl";
        $documents->open(new Document($constraintNameUri, 'yaml', 1, $constraintNameText));
        self::assertSame(['Slug'], $this->completionLabels($completionProvider, $converter, $constraintNameUri, $constraintNameText, \strlen($constraintNameText)));
    }

    public function testIgnoresCommentedValidationMetadataWhilePreservingActiveRanges(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);
        $text = <<<'PHP'
            <?php
            namespace App\Dto;

            use Symfony\Component\Validator\Constraints as Assert;

            // #[Assert\Length(commented_constraint: 1)]
            #[Assert\Length(active_constraint: 1), Assert\NotBlank]
            final class Input
            {
            }
            PHP;

        $constraintOptions = $extractor->constraintOptions($text);
        self::assertSame(['active_constraint'], array_column($constraintOptions, 'option'));
        self::assertSame(strpos($text, 'active_constraint'), $converter->toByteOffset($text, $constraintOptions[0]['range']->start));

        $symbols = $extractor->extract('file:///workspace/src/Dto/Input.php', 'php', $text)->symbols;
        $constraints = [];
        foreach ($symbols as $symbol) {
            self::assertStringNotContainsString('commented_', $symbol->name);
            if (MetadataSymbolKind::Constraint === $symbol->kind && !$symbol->declaration) {
                $constraints[] = $symbol->name;
            }
        }
        self::assertSame(['Length', 'NotBlank'], $constraints);
    }

    private function createIndexes(Project $project): MetadataIndexRegistry
    {
        $indexes = new MetadataIndexRegistry();
        $indexes->forProject($project)->replace(
            [],
            [
                new ValidationConstraint('Choice', 'Symfony\\Component\\Validator\\Constraints\\Choice', ['choices', 'groups']),
                new ValidationConstraint('Ip', 'Symfony\\Component\\Validator\\Constraints\\Ip', ['version']),
                new ValidationConstraint('Language', 'Symfony\\Component\\Validator\\Constraints\\Language', ['language']),
                new ValidationConstraint('Length', 'Symfony\\Component\\Validator\\Constraints\\Length', ['groups', 'max', 'message', 'min']),
                new ValidationConstraint('LessThan', 'Symfony\\Component\\Validator\\Constraints\\LessThan', ['value']),
                new ValidationConstraint('Type', 'Symfony\\Component\\Validator\\Constraints\\Type', ['groups', 'type']),
                new ValidationConstraint('When', 'Symfony\\Component\\Validator\\Constraints\\When', ['constraints', 'expression']),
            ],
            true,
            true,
        );

        return $indexes;
    }
}
