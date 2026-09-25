<?php

namespace Symfony\Lsp\Tests\Feature\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\RenameEditBuilder;
use Symfony\Lsp\Feature\Translation\TranslationDeclaration;
use Symfony\Lsp\Feature\Translation\TranslationExtractor;
use Symfony\Lsp\Feature\Translation\TranslationIndexRegistry;
use Symfony\Lsp\Feature\Translation\TranslationReference;
use Symfony\Lsp\Feature\Translation\TranslationReferenceResolver;
use Symfony\Lsp\Feature\Translation\TranslationRenameHandler;
use Symfony\Lsp\Feature\Translation\TranslationSourceFacts;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Tests\Support\ProjectPaths;

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
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $converter = new PositionConverter();
        $extractor = TranslationExtractorTestFactory::create($converter);
        $indexes = new TranslationIndexRegistry();
        $indexes->forProject($project)->replaceSources(
            $extractor->extract(new SourceDocument($resourceUri, 'yaml', $resource)),
            $extractor->extract(new SourceDocument($referenceUri, 'php', $reference)),
        );
        $handler = $this->createHandler($documents, $projects, $converter, $extractor, $indexes);
        $position = $converter->toPosition($reference, strpos($reference, 'article.title') + 1);

        $result = $handler->rename([
            'textDocument' => ['uri' => $referenceUri],
            'position' => ['line' => $position->line, 'character' => $position->character],
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

    public function testEmitsOneEditWhenADeclarationAndAReferenceShareARange(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = "<?php \$translator->trans('title');";
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $converter = new PositionConverter();
        $extractor = TranslationExtractorTestFactory::create($converter);
        $offset = strpos($text, 'title');
        self::assertIsInt($offset);
        $start = $converter->toPosition($text, $offset);
        $range = new Range($start, new Position($start->line, $start->character + 5));
        $indexes = new TranslationIndexRegistry();
        $indexes->forProject($project)->replaceSources(new TranslationSourceFacts(
            $uri,
            [new TranslationDeclaration('title', 'messages', 'en', 'Title', $uri, $range)],
            [new TranslationReference('title', 'messages', $uri, $range)],
        ));
        $handler = $this->createHandler($documents, $projects, $converter, $extractor, $indexes);

        $result = $handler->rename([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $start->line, 'character' => $start->character + 1],
            'newName' => 'heading',
        ]);

        self::assertIsArray($result);
        self::assertSame([[
            'textDocument' => ['uri' => $uri, 'version' => null],
            'edits' => [[
                'range' => [
                    'start' => ['line' => $start->line, 'character' => $start->character],
                    'end' => ['line' => $start->line, 'character' => $start->character + 5],
                ],
                'newText' => 'heading',
                'annotationId' => 'translationRename',
            ]],
        ]], $result['documentChanges']);
    }

    private function createHandler(
        DocumentStore $documents,
        ProjectRegistry $projects,
        PositionConverter $converter,
        TranslationExtractor $extractor,
        TranslationIndexRegistry $indexes,
    ): TranslationRenameHandler {
        $protocol = new LspProtocolMapper();

        return new TranslationRenameHandler(
            new TranslationReferenceResolver(new DocumentContextResolver($documents, $projects), $converter, $extractor),
            $protocol,
            $indexes,
            ProjectPaths::resolver(),
            new RenameEditBuilder($protocol),
        );
    }
}
