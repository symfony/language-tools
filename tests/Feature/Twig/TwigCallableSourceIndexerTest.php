<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Twig\TwigCallableDeclarationExtractor;
use Symfony\Lsp\Feature\Twig\TwigCallableIndexRegistry;
use Symfony\Lsp\Feature\Twig\TwigCallableKind;
use Symfony\Lsp\Feature\Twig\TwigCallableReferenceExtractor;
use Symfony\Lsp\Feature\Twig\TwigCallableSourceFacts;
use Symfony\Lsp\Feature\Twig\TwigCallableSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexPayloadCodec;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;

final class TwigCallableSourceIndexerTest extends TestCase
{
    public function testRestoresPersistedTwigCallableDeclarations(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $uri = 'file:///workspace/src/Twig/AppExtension.php';
        $document = new SourceDocument($uri, 'php', <<<'PHP'
            <?php
            use App\Twig\Runtime;
            use Twig\Attribute\AsTwigFunction;
            use Twig\TwigFunction;

            final class AppExtension
            {
                public function getFunctions(): array
                {
                    return [new TwigFunction('function_name', [Runtime::class, 'render'], ['needs_context' => true, 'is_variadic' => true])];
                }

                #[AsTwigFunction('attribute_name', needsCharset: true, needsContext: true, needsIsSandboxed: true)]
                public function attributed(string $charset, array $context, bool $isSandboxed, string ...$values): string
                {
                    return implode('', $values);
                }
            }
            PHP);
        $sourceIndexes = new TwigCallableIndexRegistry();
        $sourceIndexer = $this->indexer($sourceIndexes);
        $sourceIndexer->begin($project);
        $facts = $sourceIndexer->index($project, $document);
        self::assertInstanceOf(TwigCallableSourceFacts::class, $facts);
        $sourceIndexer->finish($project);

        $codec = new SourceIndexPayloadCodec();
        $codec->validate([$sourceIndexer]);
        $payload = $codec->encode($sourceIndexer->name(), $facts);
        $restoredIndexes = new TwigCallableIndexRegistry();
        $restoredIndexer = $this->indexer($restoredIndexes);
        $restoredIndexer->begin($project);
        $restoredIndexer->restore($project, $codec->decode($sourceIndexer->name(), $payload));
        $restoredIndexer->finish($project);

        $declarations = $restoredIndexes->forProject($project)->declarations(TwigCallableKind::Function, 'function_name');
        self::assertCount(1, $declarations);
        self::assertSame('App\Twig\Runtime', $declarations[0]->className());
        self::assertSame('render', $declarations[0]->method());
        self::assertTrue($declarations[0]->needsContext());
        self::assertTrue($declarations[0]->isVariadic());
        self::assertTrue($declarations[0]->optionsKnown());

        $attributes = $restoredIndexes->forProject($project)->declarations(TwigCallableKind::Function, 'attribute_name');
        self::assertCount(1, $attributes);
        self::assertSame('AppExtension', $attributes[0]->className());
        self::assertSame('attributed', $attributes[0]->method());
        self::assertTrue($attributes[0]->needsCharset());
        self::assertTrue($attributes[0]->needsContext());
        self::assertTrue($attributes[0]->needsIsSandboxed());
        self::assertTrue($attributes[0]->isVariadic());
        self::assertTrue($attributes[0]->optionsKnown());
    }

    private function indexer(TwigCallableIndexRegistry $indexes): TwigCallableSourceIndexer
    {
        $converter = new PositionConverter();

        return new TwigCallableSourceIndexer(
            $indexes,
            new TwigCallableDeclarationExtractor($converter, new TolerantPhpParser(new Parser())),
            new TwigCallableReferenceExtractor(new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), $commentParser = new TwigCommentParser()), $commentParser, $converter),
        );
    }
}
