<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolExtractor;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolIndexRegistry;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolProvider;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolSourceFacts;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigPhpSymbolProviderTest extends TestCase
{
    public function testProvidesCompletionHoverDefinitionAndReferences(): void
    {
        $converter = new PositionConverter();
        $documents = new DocumentStore();
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $phpUri = 'file:///workspace/src/Model.php';
        $php = <<<'PHP'
            <?php
            namespace App\Model;

            /** Workflow status. */
            enum Status: string
            {
                case Draft = 'draft';

                /** Ready for readers. */
                case Published = 'published';

                public const LABEL = 'Status';
            }

            final class ViewOptions
            {
                /** Output format. */
                public const FORMAT = 'html';
                private const SECRET = 'secret';
            }
            PHP;
        $twigUri = 'file:///workspace/templates/page.html.twig';
        $twig = <<<'TWIG'
            {{ constant('App\\Model\\ViewOptions::FORMAT') }}
            {{ constant('App\\Model\\Status::Published') }}
            {{ enum('App\\Model\\Status').Published }}
            {% for status in enum_cases('App\\Model\\Status') %}
                {{ status.value }}
            {% endfor %}
            TWIG;
        $documents->open(new Document($phpUri, 'php', 1, $php));
        $documents->open(new Document($twigUri, 'twig', 1, $twig));
        $extractor = $this->extractor($converter);
        $phpFacts = $extractor->extract($phpUri, 'php', $php);
        $twigFacts = $extractor->extract($twigUri, 'twig', $twig);
        self::assertInstanceOf(TwigPhpSymbolSourceFacts::class, $phpFacts);
        self::assertInstanceOf(TwigPhpSymbolSourceFacts::class, $twigFacts);
        $indexes = new TwigPhpSymbolIndexRegistry();
        $indexes->forProject($project)->replace($phpFacts, $twigFacts);
        $provider = new TwigPhpSymbolProvider(
            new DocumentContextResolver($documents, $projects),
            $converter,
            $protocol = new LspProtocolMapper(),
            $indexes,
            $extractor,
        );

        self::assertSame([
            'contents' => [
                'kind' => 'markdown',
                'value' => "PHP class constant: `App\\Model\\ViewOptions::FORMAT`\n\n```php\npublic const FORMAT;\n```\n\nOutput format.",
            ],
        ], $provider->hover($this->params($twigUri, $twig, 'FORMAT', $converter)));
        self::assertSame([
            'contents' => [
                'kind' => 'markdown',
                'value' => "PHP enum: `App\\Model\\Status`\n\n```php\nenum Status: string\n```\n\nWorkflow status.",
            ],
        ], $provider->hover($this->params($twigUri, $twig, 'App\\\\Model\\\\Status', $converter)));
        self::assertSame([
            'contents' => [
                'kind' => 'markdown',
                'value' => "PHP enum case: `App\\Model\\Status::Published`\n\n```php\ncase Published;\n```\n\nReady for readers.",
            ],
        ], $provider->hover($this->params($twigUri, $twig, 'Published', $converter, strpos($twig, ').Published') + 2)));

        $format = $indexes->forProject($project)->memberDeclarations('App\Model\ViewOptions', 'FORMAT')[0];
        self::assertSame([
            $protocol->location($phpUri, $format->range()),
        ], $provider->definition($this->params($twigUri, $twig, 'FORMAT', $converter)));
        $status = $indexes->forProject($project)->typeDeclarations('App\Model\Status')[0];
        self::assertSame([
            $protocol->location($phpUri, $status->range()),
        ], $provider->definition($this->params($twigUri, $twig, 'App\\\\Model\\\\Status', $converter)));

        self::assertCount(2, $provider->references($this->params($twigUri, $twig, 'Published', $converter, strpos($twig, ').Published') + 2)) ?? []);
        self::assertCount(2, $provider->references($this->params($phpUri, $php, 'Published', $converter)) ?? []);
        self::assertCount(3, $provider->references($this->params($phpUri, $php, 'Status', $converter)) ?? []);
        $withDeclaration = $this->params($phpUri, $php, 'Published', $converter);
        $withDeclaration['context'] = ['includeDeclaration' => true];
        self::assertCount(3, $provider->references($withDeclaration) ?? []);
        self::assertCount(1, $provider->references($this->params($phpUri, $php, 'ViewOptions', $converter, strpos($php, 'ViewOptions') + 2)) ?? []);

        $complete = static function (string $text, ?int $cursor = null) use ($provider, $documents, $converter): array {
            $uri = 'file:///workspace/templates/completion.html.twig';
            $documents->open(new Document($uri, 'twig', 2, $text));
            $position = $converter->toPosition($text, $cursor ?? \strlen($text));

            return $provider->complete([
                'textDocument' => ['uri' => $uri],
                'position' => ['line' => $position->line, 'character' => $position->character],
            ]) ?? [];
        };
        $items = $complete("{{ constant('App\\\\Model\\\\Vie");
        self::assertSame(['App\Model\ViewOptions'], array_column($items, 'label'));
        self::assertSame('App\\\\Model\\\\ViewOptions', $items[0]['filterText']);
        $textEdit = $items[0]['textEdit'];
        self::assertIsArray($textEdit);
        self::assertSame('App\\\\Model\\\\ViewOptions', $textEdit['newText']);
        self::assertSame(['FORMAT'], array_column($complete("{{ constant('App\\\\Model\\\\ViewOptions::F"), 'label'));
        self::assertSame(['FORMAT'], array_column($complete("{{ constant('App\\\\Model\\\\ViewOptions::"), 'label'));
        self::assertSame(['App\Model\Status'], array_column($complete("{{ enum_cases('App\\\\Model\\\\St"), 'label'));
        self::assertSame(['Draft', 'Published'], array_column($complete("{{ enum('App\\\\Model\\\\Status')."), 'label'));
        self::assertSame([], $complete("{# {{ enum('App\\\\Model\\\\Status')."));

        $midLiteral = "{{ constant('App\\\\Model\\\\ViewOptions::FORMAT') }}";
        $cursor = strpos($midLiteral, 'ViewOptions');
        self::assertIsInt($cursor);
        $items = $complete($midLiteral, $cursor + 3);
        $textEdit = $items[0]['textEdit'];
        self::assertIsArray($textEdit);
        $range = $textEdit['range'];
        self::assertIsArray($range);
        $start = $range['start'];
        $end = $range['end'];
        self::assertIsArray($start);
        self::assertIsArray($end);
        self::assertIsInt($start['line']);
        self::assertIsInt($start['character']);
        self::assertIsInt($end['line']);
        self::assertIsInt($end['character']);
        $startOffset = $converter->toByteOffset($midLiteral, new Position($start['line'], $start['character']));
        $endOffset = $converter->toByteOffset($midLiteral, new Position($end['line'], $end['character']));
        self::assertSame('App\\\\Model\\\\ViewOptions', substr($midLiteral, $startOffset, $endOffset - $startOffset));
    }

    private function extractor(PositionConverter $converter): TwigPhpSymbolExtractor
    {
        $comments = new TwigCommentParser();

        return new TwigPhpSymbolExtractor(
            $converter,
            new TolerantPhpParser(new Parser()),
            new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), $comments),
            $comments,
        );
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function params(string $uri, string $text, string $needle, PositionConverter $converter, int|false|null $offset = null): array
    {
        $offset = null === $offset ? strpos($text, $needle) : $offset;
        self::assertIsInt($offset);
        $position = $converter->toPosition($text, $offset + intdiv(\strlen($needle), 2));

        return [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ];
    }
}
