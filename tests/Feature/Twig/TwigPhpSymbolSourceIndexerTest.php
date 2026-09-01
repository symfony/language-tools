<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolCompletionContextResolver;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolDeclarationExtractor;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolExtractor;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolIndexRegistry;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolKind;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolReferenceExtractor;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolSourceFacts;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigArgumentParser;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;

final class TwigPhpSymbolSourceIndexerTest extends TestCase
{
    public function testPersistsDeclarationsReferencesAndLookupIndexes(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $phpUri = 'file:///workspace/src/Model.php';
        $twigUri = 'file:///workspace/templates/page.html.twig';
        $php = <<<'PHP'
            <?php
            namespace App;

            enum Status
            {
                case Draft;
                case Published;
            }

            final class Options
            {
                public const FORMAT = 'html';
            }
            PHP;
        $twig = <<<'TWIG'
            {{ constant('App\\Options::FORMAT') }}
            {{ enum('App\\Status').Published }}
            TWIG;
        $indexes = new TwigPhpSymbolIndexRegistry();
        $indexer = $this->indexer($indexes);
        $indexer->begin($project);
        $phpFacts = $indexer->index($project, new SourceDocument($phpUri, 'php', $php));
        $twigFacts = $indexer->index($project, new SourceDocument($twigUri, 'twig', $twig));
        self::assertInstanceOf(TwigPhpSymbolSourceFacts::class, $phpFacts);
        self::assertInstanceOf(TwigPhpSymbolSourceFacts::class, $twigFacts);
        $indexer->finish($project);

        $index = $indexes->forProject($project);
        self::assertSame(['App\Status'], $index->enumNames());
        self::assertSame(['App\Options', 'App\Status'], $index->constantTypeNames());
        self::assertSame(['FORMAT'], array_map(static fn ($declaration): ?string => $declaration->memberName, $index->completableMembers('app\options', false)));
        self::assertSame(['Draft', 'Published'], array_map(static fn ($declaration): ?string => $declaration->memberName, $index->completableMembers('App\Status', true)));
        self::assertCount(1, $index->references('App\Status', 'Published'));
        $published = $index->memberDeclarations('App\Status', 'Published')[0];
        self::assertSame(TwigPhpSymbolKind::EnumCase, $published->kind);
        self::assertSame($published, $index->declarationAt($phpUri, new Position($published->range->start->line, $published->range->start->character)));

        $codec = new SourceIndexPayloadCodec();
        $codec->validate([$indexer]);
        $restoredIndexes = new TwigPhpSymbolIndexRegistry();
        $restoredIndexer = $this->indexer($restoredIndexes);
        $restoredIndexer->begin($project);
        $restoredIndexer->restore($project, $codec->decode($indexer->name(), $codec->encode($indexer->name(), $phpFacts)));
        $restoredIndexer->restore($project, $codec->decode($indexer->name(), $codec->encode($indexer->name(), $twigFacts)));
        $restoredIndexer->finish($project);

        self::assertCount(1, $restoredIndexes->forProject($project)->memberDeclarations('App\Options', 'FORMAT'));
        self::assertCount(1, $restoredIndexes->forProject($project)->references('App\Status', 'Published'));
    }

    public function testOpenDocumentOverlayReplacesSavedSymbols(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $uri = 'file:///workspace/src/Status.php';
        $extractor = $this->extractor(new PositionConverter());
        $index = (new TwigPhpSymbolIndexRegistry())->forProject($project);
        $saved = $extractor->extract($uri, 'php', '<?php enum Status { case Saved; }');
        $unsaved = $extractor->extract($uri, 'php', '<?php enum Status { case Unsaved; }');
        self::assertInstanceOf(TwigPhpSymbolSourceFacts::class, $saved);
        self::assertInstanceOf(TwigPhpSymbolSourceFacts::class, $unsaved);

        $index->replace($saved);
        $index->overlay($unsaved);

        self::assertSame([], $index->memberDeclarations('Status', 'Saved'));
        self::assertCount(1, $index->memberDeclarations('Status', 'Unsaved'));

        $index->removeOverlay($uri);

        self::assertCount(1, $index->memberDeclarations('Status', 'Saved'));
        self::assertSame([], $index->memberDeclarations('Status', 'Unsaved'));
    }

    private function indexer(TwigPhpSymbolIndexRegistry $indexes): TwigPhpSymbolSourceIndexer
    {
        $converter = new PositionConverter();
        $comments = new TwigCommentParser();

        return new TwigPhpSymbolSourceIndexer(
            $indexes,
            new TolerantPhpParser(new Parser()),
            new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), $comments),
            new TwigPhpSymbolDeclarationExtractor($converter),
            new TwigPhpSymbolReferenceExtractor($converter, new TwigCallArgumentResolver(new TwigArgumentParser())),
        );
    }

    private function extractor(PositionConverter $converter): TwigPhpSymbolExtractor
    {
        $comments = new TwigCommentParser();

        return new TwigPhpSymbolExtractor(
            $converter,
            new TolerantPhpParser(new Parser()),
            new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), $comments),
            new TwigPhpSymbolDeclarationExtractor($converter),
            new TwigPhpSymbolReferenceExtractor($converter, new TwigCallArgumentResolver(new TwigArgumentParser())),
            new TwigPhpSymbolCompletionContextResolver($converter, $comments, new TwigDirectiveLocator()),
        );
    }
}
