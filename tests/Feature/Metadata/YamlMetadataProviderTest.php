<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Metadata\MetadataCompletionProvider;
use Symfony\Lsp\Feature\Metadata\MetadataIndexRegistry;
use Symfony\Lsp\Feature\Metadata\MetadataSourceIndexRegistry;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class YamlMetadataProviderTest extends MetadataTestCase
{
    public function testCompletesMappedProperties(): void
    {
        $converter = new PositionConverter();
        $extractor = $this->createExtractor($converter);
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $projects = new ProjectRegistry();
        $projects->replace([$project]);
        $entityText = <<<'PHP'
            <?php
            namespace App\Entity;
            final class User
            {
                public string $email;
            }
            PHP;
        $sourceIndexes = new MetadataSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace($extractor->extract(new SourceDocument('file:///workspace/src/Entity/User.php', 'php', $entityText)));
        $documents = new DocumentStore();
        $resolver = new DocumentContextResolver($documents, $projects);
        $completionProvider = new MetadataCompletionProvider($resolver, $converter, new LspProtocolMapper(), new MetadataIndexRegistry(), $sourceIndexes, $extractor);
        $propertyUri = 'file:///workspace/config/serializer/Completion.yaml';
        $propertyText = "App\\Entity\\User:\n    attributes:\n        em";
        $documents->open(new Document($propertyUri, 'yaml', 1, $propertyText));

        self::assertSame(['email'], $this->completionLabels($completionProvider, $converter, $propertyUri, $propertyText, \strlen($propertyText)));
    }
}
