<?php

namespace Symfony\Lsp\Tests\Feature\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Translation\TranslationExtractor;
use Symfony\Lsp\Feature\Translation\TranslationIndexRegistry;
use Symfony\Lsp\Feature\Translation\TranslationRenameHandler;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

final class TranslationRenameHandlerTest extends TestCase
{
    public function testRenamesNestedSourceKeysAndStaticReferences(): void
    {
        $resourceUri = 'file:///workspace/translations/messages.en.yaml';
        $resource = "article:\n    title: Article\n";
        $referenceUri = 'file:///workspace/src/Controller.php';
        $reference = "<?php \$translator->trans('article.title');";
        $documents = new DocumentStore();
        $documents->open(new Document($referenceUri, 'php', 1, $reference));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $extractor = new TranslationExtractor($converter, new UriToPathConverter());
        $indexes = new TranslationIndexRegistry();
        $indexes->forProject($project)->replaceSources(
            $extractor->extract($resourceUri, 'yaml', $resource),
            $extractor->extract($referenceUri, 'php', $reference),
        );
        $handler = new TranslationRenameHandler(
            new DocumentContextResolver($documents, $projects),
            $converter,
            $extractor,
            $indexes,
        );
        $position = $converter->toPosition($reference, strpos($reference, 'article.title') + 1);

        $result = $handler->rename([
            'textDocument' => ['uri' => $referenceUri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
            'newName' => 'article.heading',
        ]);
        self::assertIsArray($result);
        self::assertIsArray($result['documentChanges']);
        $newTexts = [];
        foreach ($result['documentChanges'] as $change) {
            self::assertIsArray($change);
            self::assertIsArray($change['edits']);
            self::assertIsArray($change['edits'][0]);
            self::assertIsString($change['edits'][0]['newText']);
            $newTexts[] = $change['edits'][0]['newText'];
        }
        self::assertSame(['article.heading', 'heading'], $newTexts);
    }
}
