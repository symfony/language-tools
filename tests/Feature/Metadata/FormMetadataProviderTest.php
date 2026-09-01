<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Metadata\FormMetadataProvider;
use Symfony\Lsp\Feature\Metadata\FormType;
use Symfony\Lsp\Feature\Metadata\MetadataCompletionProvider;
use Symfony\Lsp\Feature\Metadata\MetadataIndexRegistry;
use Symfony\Lsp\Feature\Metadata\MetadataRelationshipProvider;
use Symfony\Lsp\Feature\Metadata\MetadataSourceIndexRegistry;
use Symfony\Lsp\Feature\Metadata\MetadataSymbolKind;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class FormMetadataProviderTest extends MetadataTestCase
{
    public function testProvidesFormMetadata(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $indexes = new MetadataIndexRegistry();
        $indexes->forProject($project)->replace(
            [new FormType('App\\Form\\EventType', 'event', ['action', 'required'], ['required'])],
            [],
            true,
            true,
        );
        $documents = new DocumentStore();
        $resolver = new DocumentContextResolver($documents, $projects);
        $protocol = new LspProtocolMapper();
        $sourceIndexes = new MetadataSourceIndexRegistry();
        $completionProvider = new MetadataCompletionProvider($resolver, $converter, $protocol, $indexes, $sourceIndexes, $extractor);
        $formProvider = new FormMetadataProvider($resolver, $converter, $protocol, $indexes, $extractor);
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
        self::assertSame(['required'], $this->completionLabels($completionProvider, $converter, $formUri, $formText, $firstRequired + 4));
        $builderRequired = strpos($formText, 'required', $firstRequired + 1);
        self::assertSame(['required'], $this->completionLabels($completionProvider, $converter, $formUri, $formText, $builderRequired + 4));
        self::assertSame(['form.unknown_option'], array_column($this->diagnostics([$formProvider], $formUri), 'code'));
        $required = strpos($formText, 'required') + 1;
        self::assertIsArray($this->hover([$formProvider], $converter, $formUri, $formText, $required));
    }

    public function testLinksFormFieldsToDataClassProperties(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $dtoUri = 'file:///workspace/src/Dto/Article.php';
        $dtoText = <<<'PHP'
            <?php
            namespace App\Dto;

            final class Article
            {
                /** The article title. */
                private ?string $title = null;

                public string $summary;
            }
            PHP;
        $formUri = 'file:///workspace/src/Form/ArticleType.php';
        $formText = <<<'PHP'
            <?php
            namespace App\Form;

            use App\Dto\Article;
            use Symfony\Component\Form\AbstractType;
            use Symfony\Component\Form\Extension\Core\Type\TextType;
            use Symfony\Component\Form\FormBuilderInterface;
            use Symfony\Component\OptionsResolver\OptionsResolver;

            final class ArticleType extends AbstractType
            {
                public function buildForm(FormBuilderInterface $builder, array $options, object $menu): void
                {
                    $builder
                        ->add('title', TextType::class)
                        ->add('headline', TextType::class, ['property_path' => 'summary'])
                        ->add('ignored', TextType::class, ['mapped' => false])
                        ->add('dynamic', TextType::class, $options)
                    ;
                    $builder->get('author')->add('street', TextType::class);
                    $builder->add('named', options: ['mapped' => false]);
                    $builder->addEventListener('event', fn ($event) => $event->getForm()->add('leaked', TextType::class));
                    $menu->add('unrelated', TextType::class);
                }

                public function configureOptions(OptionsResolver $resolver): void
                {
                    $resolver->setDefaults([
                        'data_class' => Article::class,
                    ]);
                }
            }

            final class AlternateArticleType extends AbstractType
            {
                public function configureOptions(OptionsResolver $resolver): void
                {
                    $resolver->setDefault('data_class', Article::class);
                }
            }

            final class DynamicArticleType extends AbstractType
            {
                public function configureOptions(OptionsResolver $resolver, string $class): void
                {
                    $resolver->setDefaults(['data_class' => $class]);
                }
            }
            PHP;
        $formFacts = $extractor->extract(new SourceDocument($formUri, 'php', $formText));
        $dataClasses = [];
        foreach ($formFacts->formDataClasses as $formDataClass) {
            $dataClasses[$formDataClass->formClass] = $formDataClass->dataClass;
        }
        self::assertSame([
            'App\\Form\\ArticleType' => 'App\\Dto\\Article',
            'App\\Form\\AlternateArticleType' => 'App\\Dto\\Article',
        ], $dataClasses);

        $sourceIndexes = new MetadataSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace(
            $extractor->extract(new SourceDocument($dtoUri, 'php', $dtoText)),
            $formFacts,
        );
        $documents = new DocumentStore();
        $documents->open(new Document($dtoUri, 'php', 1, $dtoText));
        $documents->open(new Document($formUri, 'php', 1, $formText));
        $resolver = new DocumentContextResolver($documents, $projects);
        $protocol = new LspProtocolMapper();
        $relationshipProvider = new MetadataRelationshipProvider($resolver, new PositionedSourceSymbolResolver($converter), $protocol, $sourceIndexes, $extractor);
        $completionProvider = new MetadataCompletionProvider($resolver, $converter, $protocol, new MetadataIndexRegistry(), $sourceIndexes, $extractor);

        $titleOffset = strpos($formText, "'title'") + 2;
        $hover = $relationshipProvider->hover($this->params($converter, $formUri, $formText, $titleOffset));
        self::assertIsArray($hover);
        $hoverValue = \is_array($hover['contents'] ?? null) ? ($hover['contents']['value'] ?? null) : null;
        self::assertIsString($hoverValue);
        self::assertStringContainsString('PHP property: `App\\Dto\\Article::$title`', $hoverValue);
        self::assertStringContainsString('private ?string $title', $hoverValue);
        self::assertStringContainsString('The article title.', $hoverValue);

        $definition = $relationshipProvider->definition($this->params($converter, $formUri, $formText, $titleOffset));
        self::assertIsArray($definition);
        self::assertSame([$dtoUri], array_column($definition, 'uri'));

        $propertyOffset = strpos($dtoText, '$title') + 2;
        $references = $relationshipProvider->references($this->params($converter, $dtoUri, $dtoText, $propertyOffset));
        self::assertIsArray($references);
        $referenceUris = [];
        foreach ($references as $reference) {
            self::assertIsString($reference['uri'] ?? null);
            $referenceUris[$reference['uri']] = true;
        }
        self::assertSame([$dtoUri, $formUri], array_keys($referenceUris));

        self::assertSame(['title'], $this->completionLabels($completionProvider, $converter, $formUri, $formText, strpos($formText, "'title'") + \strlen("'ti")));
        $headlineDefinition = $relationshipProvider->definition($this->params($converter, $formUri, $formText, strpos($formText, "'headline'") + 2));
        self::assertIsArray($headlineDefinition);
        self::assertSame([$dtoUri], array_column($headlineDefinition, 'uri'));
        self::assertNull($relationshipProvider->definition($this->params($converter, $formUri, $formText, strpos($formText, "'ignored'") + 2)));
        self::assertNull($relationshipProvider->definition($this->params($converter, $formUri, $formText, strpos($formText, "'dynamic'") + 2)));
        self::assertNull($relationshipProvider->definition($this->params($converter, $formUri, $formText, strpos($formText, "'street'") + 2)));
        self::assertNull($relationshipProvider->definition($this->params($converter, $formUri, $formText, strpos($formText, "'named'") + 2)));
        self::assertNull($relationshipProvider->definition($this->params($converter, $formUri, $formText, strpos($formText, "'leaked'") + 2)));
        self::assertNull($relationshipProvider->definition($this->params($converter, $formUri, $formText, strpos($formText, "'unrelated'") + 2)));
        self::assertSame([], $this->completionLabels($completionProvider, $converter, $formUri, $formText, strpos($formText, "'street'") + \strlen("'str")));
    }

    public function testScopesCompleteMetadataCallsToTheirTypedParameters(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);
        $text = <<<'PHP'
            <?php
            namespace App\Form;

            use App\Dto\Article;
            use App\Form\ArticleType as NestedArticleType;
            use Symfony\Component\Form\FormBuilderInterface;
            use Symfony\Component\OptionsResolver\OptionsResolver;

            final class ArticleType
            {
                public function configureOptions(OptionsResolver $resolver): void
                {
                    $resolver->setDefault('data_class', Article::class);
                }

                public function unrelated(object $resolver): void
                {
                    $resolver->setDefault('data_class', Ignored::class);
                }

                public function buildForm(FormBuilderInterface $builder): void
                {
                    $builder->add('title', /* type */ NestedArticleType::class, ['active_option' => true]);
                }

                public function controller(): void
                {
                    $this->createForm($this->resolve(NestedArticleType::class), null, ['nested_option' => true]);
                }

                public function unrelatedBuilder(object $builder): void
                {
                    $builder->add('ignored', NestedArticleType::class, ['ignored_option' => true]);
                }
            }
            PHP;

        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Form/ArticleType.php', 'php', $text));
        $dataClasses = [];
        foreach ($facts->formDataClasses as $dataClass) {
            $dataClasses[$dataClass->formClass] = $dataClass->dataClass;
        }
        self::assertSame(['App\Form\ArticleType' => 'App\Dto\Article'], $dataClasses);
        self::assertSame(['active_option'], array_column($extractor->formOptions($text), 'option'));
        self::assertNull($extractor->completionContext('php', $text, strpos($text, "'ignored'") + \strlen("'ign")));

        $references = array_values(array_filter(
            $facts->symbols,
            static fn ($symbol): bool => MetadataSymbolKind::Property === $symbol->kind && !$symbol->declaration,
        ));
        self::assertSame(['App\Dto\Article::$title'], array_map(static fn ($symbol): string => $symbol->name, $references));
        self::assertSame(strpos($text, "'title'") + 1, $converter->toByteOffset($text, $references[0]->range->start));
    }

    public function testKeepsOptionsAfterClosingBracketsInNestedStrings(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);
        $text = <<<'PHP'
            <?php
            use App\Form\EventType;

            $this->createForm(EventType::class, null, [
                'attr' => [
                    'data-pattern' => 'prefix]suffix',
                ],
                'required' => true,
            ]);
            PHP;

        $options = $extractor->formOptions($text);

        self::assertSame(['attr', 'required'], array_column($options, 'option'));
        self::assertSame(strpos($text, "'required'") + 1, $converter->toByteOffset($text, $options[1]['range']->start));
    }

    public function testIgnoresNamedArgumentsInPositionalMetadataSlots(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);
        $text = <<<'PHP'
            <?php
            use App\Form\EventType;

            $this->createForm(unrelated: EventType::class, data: null, options: [
                'ignored_option' => true,
            ]);
            PHP;

        self::assertSame([], $extractor->formOptions($text));
    }

    public function testIgnoresCommentedFormMetadataWhilePreservingActiveRanges(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);
        $text = <<<'PHP'
            <?php
            namespace App\Controller;

            use App\Form\EventType;
            use Symfony\Component\Form\FormBuilderInterface;

            final class EventController
            {
                public function build(FormBuilderInterface $builder): void
                {
                    // $this->createForm(EventType::class, null, ['commented_create_form' => true]);
                    /* $this->createNamed('event', EventType::class, null, ['commented_create_named' => true]); */
                    // $builder->add('event', EventType::class, ['commented_add' => true]);
                    $this->createForm(EventType::class, null, ['active_form' => true]);
                }
            }
            PHP;

        $formOptions = $extractor->formOptions($text);
        self::assertSame(['active_form'], array_column($formOptions, 'option'));
        self::assertSame(strpos($text, 'active_form'), $converter->toByteOffset($text, $formOptions[0]['range']->start));

        $uri = 'file:///workspace/src/Controller/EventController.php';
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $indexes = new MetadataIndexRegistry();
        $indexes->forProject($project)->replace(
            [new FormType('App\\Form\\EventType', 'event', ['active_form'], [])],
            [],
            true,
            true,
        );
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $resolver = new DocumentContextResolver($documents, $projects);
        self::assertSame([], (new FormMetadataProvider($resolver, $converter, new LspProtocolMapper(), $indexes, $extractor))->diagnostics(['textDocument' => ['uri' => $uri]]));
    }
}
