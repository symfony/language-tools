<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Twig\TwigCallableArgumentReference;
use Symfony\Lsp\Feature\Twig\TwigCallableDeclarationExtractor;
use Symfony\Lsp\Feature\Twig\TwigCallableReferenceExtractor;
use Symfony\Lsp\Feature\Twig\TwigCallableSourceFacts;
use Symfony\Lsp\Feature\Twig\TwigCallableSourceIndexer;
use Symfony\Lsp\Feature\Twig\TwigCallableSourceIndexRegistry;
use Symfony\Lsp\Feature\Twig\TwigCallableUsage;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceParseHealth;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;

final class TwigCallableSourceIndexerTest extends TestCase
{
    public function testExtractsUsagesAndNamedArgumentsFromTwigSyntax(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $uri = 'file:///workspace/templates/callables.html.twig';
        $text = <<<'TWIG'
            Plain ignored(named: 1)
            {# {{ commented(named: 1)|hidden }} #}
            {% macro declared(value = 1) %}{% endmacro %}
            {{ 'é' ~ café(primary = nested(inner: 1), positional)|decorate(option: 2)|raw }}
            {{ object.method(named: 1) }}
            {{ broken ??? }}
            {{ final_call(value = 3) }}
            {{ missing_value(value=) }}
            TWIG;
        $indexes = new TwigCallableSourceIndexRegistry();
        $indexer = $this->indexer($indexes);
        $indexer->begin($project);
        $facts = $indexer->index($project, new SourceDocument($uri, 'twig', $text));
        self::assertInstanceOf(TwigCallableSourceFacts::class, $facts);
        $indexer->finish($project);

        self::assertSame(
            ['function:café', 'function:nested', 'filter:decorate', 'filter:raw', 'function:final_call', 'function:missing_value'],
            array_map(static fn (TwigCallableUsage $usage): string => $usage->kind->value.':'.$usage->name, $facts->usages),
        );
        self::assertSame(
            [3, 9, 3, 13],
            [$facts->usages[0]->range->start->line, $facts->usages[0]->range->start->character, $facts->usages[0]->range->end->line, $facts->usages[0]->range->end->character],
        );
        self::assertSame(
            ['function:nested', 'function:café', 'filter:decorate', 'function:final_call', 'function:missing_value'],
            array_map(static fn ($call): string => $call->kind->value.':'.$call->name, $facts->calls),
        );
        self::assertSame(
            [['inner'], ['primary'], ['option'], ['value'], ['value']],
            array_map(
                static fn ($call): array => array_map(static fn (TwigCallableArgumentReference $argument): string => $argument->name, $call->arguments),
                $facts->calls,
            ),
        );

        $converter = new PositionConverter();
        foreach ($facts->calls as $call) {
            foreach ($call->arguments as $argument) {
                $start = $converter->toByteOffset($text, $argument->range->start);
                $end = $converter->toByteOffset($text, $argument->range->end);
                self::assertSame($argument->name, substr($text, $start, $end - $start));
            }
        }
    }

    public function testPreservesRecoveredUsagesThatAreNotValidCalls(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $indexer = $this->indexer(new TwigCallableSourceIndexRegistry());
        $indexer->begin($project);
        $facts = $indexer->index($project, new SourceDocument(
            'file:///workspace/templates/malformed.html.twig',
            'twig',
            '{% a ~ name](x: 1) %}',
        ));
        self::assertInstanceOf(TwigCallableSourceFacts::class, $facts);

        self::assertSame(['name'], array_map(static fn (TwigCallableUsage $usage): string => $usage->name, $facts->usages));
        self::assertSame([], $facts->calls);
    }

    public function testIndexesOnlyCallsWithNamedArguments(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $uri = 'file:///workspace/templates/page.html.twig';
        $text = <<<'TWIG'
            {{ positional('value') }}
            {{ named(value: 'value') }}
            {{ item|filtered }}
            TWIG;
        $indexes = new TwigCallableSourceIndexRegistry();
        $indexer = $this->indexer($indexes);
        $indexer->begin($project);
        $facts = $indexer->index($project, new SourceDocument($uri, 'twig', $text));
        self::assertInstanceOf(TwigCallableSourceFacts::class, $facts);
        $indexer->finish($project);

        self::assertSame(['positional', 'named', 'filtered'], array_map(static fn (TwigCallableUsage $usage): string => $usage->name, $facts->usages));
        self::assertCount(1, $facts->calls);
        self::assertSame('named', $facts->calls[0]->name);
        self::assertSame(['value'], array_map(static fn (TwigCallableArgumentReference $argument): string => $argument->name, $facts->calls[0]->arguments));

        $indexer->overlay($project, new Document($uri, 'twig', 2, "{{ positional('changed') }}\n{{ item|filtered }}"), SourceParseHealth::Healthy);
        $overlay = $indexes->forProject($project)->factsForUri($uri);
        self::assertInstanceOf(TwigCallableSourceFacts::class, $overlay);
        self::assertSame(['positional', 'filtered'], array_map(static fn (TwigCallableUsage $usage): string => $usage->name, $overlay->usages));
        self::assertSame([], $overlay->calls);

        $indexer->removeOverlay($project, $uri);
        self::assertSame($facts, $indexes->forProject($project)->factsForUri($uri));
    }

    private function indexer(TwigCallableSourceIndexRegistry $indexes): TwigCallableSourceIndexer
    {
        $converter = new PositionConverter();

        $references = new TwigCallableReferenceExtractor(
            new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), new TwigCommentParser(), new TwigDirectiveLocator()),
            $converter,
            new TwigDirectiveLocator(),
            new TwigCallArgumentResolver(),
        );

        return new TwigCallableSourceIndexer(
            $indexes,
            new TwigCallableDeclarationExtractor($converter, new TolerantPhpParser(new Parser())),
            $references,
        );
    }
}
