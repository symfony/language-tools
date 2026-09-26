<?php

namespace Symfony\Lsp\Tests\Parser\Yaml;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterParserInterface;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterTree;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlMapping;
use Symfony\Lsp\Parser\Yaml\YamlRecoveryParser;
use Symfony\Lsp\Parser\Yaml\YamlScalar;
use Symfony\Lsp\Parser\Yaml\YamlScalarDecoder;
use Symfony\Lsp\Parser\Yaml\YamlScalarStyle;
use Symfony\Lsp\Parser\Yaml\YamlSequenceItem;
use Symfony\Lsp\Tests\Support\RecordingTreeSitterParser;

final class YamlDocumentParserTest extends TestCase
{
    public function testPreservesMappingsAfterAnIncompleteFlowCollection(): void
    {
        $source = <<<'YAML'
            framework:
                messenger:
                    routing:
                        App\Message\Report: [async
            services:
                App\Handler\ReportHandler:
                    arguments:
                        - !service '@app.reporter'
            YAML;
        $mappings = (new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))->parse($source);

        self::assertContains(
            ['services', 'App\Handler\ReportHandler', 'arguments'],
            array_map(static fn (YamlMapping $mapping): array => $mapping->path, $mappings),
        );
    }

    public function testKeepsTheAncestorsOfMappingsBesideAnIncompleteFlowCollection(): void
    {
        $source = <<<'YAML'
            framework:
                messenger:
                    default_bus: command.bus
                    transports:
                        async: '%env(MESSENGER_TRANSPORT_DSN)%'
                    routing:
                        App\Message\Report: [as
            YAML;
        $mappings = (new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))->parse($source);

        self::assertSame(
            [
                ['framework'],
                ['framework', 'messenger'],
                ['framework', 'messenger', 'default_bus'],
                ['framework', 'messenger', 'transports'],
                ['framework', 'messenger', 'transports', 'async'],
                ['framework', 'messenger', 'routing'],
                ['framework', 'messenger', 'routing', 'App\Message\Report'],
            ],
            array_map(static fn (YamlMapping $mapping): array => $mapping->path, $mappings),
        );
    }

    public function testResolvesTheParentPathAtAnIncompleteLine(): void
    {
        $source = <<<'YAML'
            framework:
                messenger:
                    default_bus: command.bus
                    routing:
                        App\Message\Report: [as
            YAML;
        $parser = new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()));

        self::assertSame(['framework', 'messenger', 'routing'], $parser->parentPath($source, (int) strpos($source, 'App\Message')));
        self::assertSame(['framework', 'messenger'], $parser->parentPath($source, (int) strpos($source, 'routing:')));
    }

    /** @param list<string> $expected */
    #[DataProvider('incompleteInputProvider')]
    public function testResolvesTheParentPathOnIncompleteTrailingInput(string $yaml, array $expected): void
    {
        $cursor = strpos($yaml, '|');
        self::assertIsInt($cursor);
        $source = str_replace('|', '', $yaml);
        $parser = new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()));

        self::assertSame($expected, $parser->parentPath($source, $cursor));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function incompleteInputProvider(): iterable
    {
        yield 'unfinished key' => [<<<'YAML'
            framework:
                messenger:
                    default_bus: command.bus
                    rou|
            YAML, ['framework', 'messenger']];
        yield 'after a colon' => [<<<'YAML'
            framework:
                cache:
                    app: |
            YAML, ['framework', 'cache', 'app']];
        yield 'unfinished value' => [<<<'YAML'
            framework:
                cache:
                    app: cache.ada|
            YAML, ['framework', 'cache']];
        yield 'indented blank line' => [<<<'YAML'
            framework:
                messenger:
                    transports:
            |
            YAML, ['framework', 'messenger', 'transports']];
        yield 'unfinished sequence item' => [<<<'YAML'
            security:
                role_hierarchy:
                    ROLE_ADMIN:
                        - ROLE_US|
            YAML, ['security', 'role_hierarchy', 'ROLE_ADMIN']];
        yield 'unterminated flow sequence' => [<<<'YAML'
            framework:
                messenger:
                    routing:
                        App\Message\Report: [as|
            YAML, ['framework', 'messenger', 'routing']];
        yield 'after an unterminated flow sequence' => [<<<'YAML'
            framework:
                messenger:
                    routing:
                        App\Message\Report: [async
                        rou|
            YAML, ['framework', 'messenger', 'routing']];
        yield 'unterminated flow mapping' => [<<<'YAML'
            services:
                App\Foo:
                    tags:
            |            - { name: 
            YAML, ['services', 'App\Foo', 'tags']];
        yield 'environment section' => [<<<'YAML'
            when@test:
                framework:
                    router:
            |
            YAML, ['framework', 'router']];
    }

    public function testReusesTheParsedDocumentAcrossAlternatingCalls(): void
    {
        $source = <<<'YAML'
            framework:
                messenger:
                    default_bus: command.bus
                    routing:
                        App\Message\Report: [as
            YAML;
        $inner = new RecordingTreeSitterParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()));
        $parser = new YamlDocumentParser($inner);

        $parser->parentPath($source, (int) strpos($source, 'App\Message'));
        $parser->parse($source);
        $parser->parentPath($source, (int) strpos($source, 'routing:'));
        $parser->parse($source);
        $parser->parentPath($source."\n", 0);

        self::assertSame([['yaml', $source], ['yaml', $source."\n"]], $inner->calls);
    }

    public function testParsesAgainOnlyWhenTheReusedDocumentLacksScalars(): void
    {
        $source = <<<'YAML'
            framework:
                router:
                    utf8: true
            YAML;
        $inner = new RecordingTreeSitterParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()));
        $parser = new YamlDocumentParser($inner);

        $parser->parse($source);
        $document = $parser->parseDocument($source);

        self::assertSame($document, $parser->parseDocument($source));
        self::assertSame($document->mappings, $parser->parse($source));
        self::assertSame([['yaml', $source], ['yaml', $source]], $inner->calls);
    }

    public function testKeepsEnvironmentScopeOutsideTheConfigurationPath(): void
    {
        $source = <<<'YAML'
            when@test:
                framework:
                    router:
                        utf8: true
            YAML;
        $mappings = (new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))->parse($source);

        self::assertSame(
            [
                [['framework'], 'when@test'],
                [['framework', 'router'], 'when@test'],
                [['framework', 'router', 'utf8'], 'when@test'],
            ],
            array_map(static fn (YamlMapping $mapping): array => [$mapping->path, $mapping->scope], $mappings),
        );
    }

    /** @param list<array{list<string>, string}> $expected */
    #[DataProvider('environmentSectionErrorProvider')]
    public function testKeepsEnvironmentScopeOfMappingsAroundAnErrorRegion(string $source, array $expected): void
    {
        $mappings = (new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))->parse($source);

        self::assertSame($expected, array_map(static fn (YamlMapping $mapping): array => [$mapping->path, $mapping->scope], $mappings));
    }

    /** @return iterable<string, array{string, list<array{list<string>, string}>}> */
    public static function environmentSectionErrorProvider(): iterable
    {
        yield 'unfinished key' => [<<<'YAML'
            framework:
                secret: foo
            when@prod:
                framework:
                    cache
                    router:
                        utf8: true
            YAML, [
            [['framework'], 'base'],
            [['framework', 'secret'], 'base'],
            [['framework'], 'when@prod'],
            [['framework', 'router'], 'when@prod'],
            [['framework', 'router', 'utf8'], 'when@prod'],
        ]];
        yield 'malformed line' => [<<<'YAML'
            when@prod:
                services:
                    ]broken
                    App\Foo:
                        public: true
            YAML, [
            [['services'], 'when@prod'],
            [['services', 'App\Foo'], 'when@prod'],
            [['services', 'App\Foo', 'public'], 'when@prod'],
        ]];
        yield 'unterminated flow collection' => [<<<'YAML'
            when@prod:
                [
                framework:
                    router: true
            YAML, [
            [['framework'], 'when@prod'],
            [['framework', 'router'], 'when@prod'],
        ]];
        yield 'unbalanced flow value' => [<<<'YAML'
            when@prod:
                cache: }
                framework:
                    router: true
            YAML, [
            [['cache'], 'when@prod'],
            [['framework'], 'when@prod'],
            [['framework', 'router'], 'when@prod'],
        ]];
    }

    public function testDistinguishesSiblingSequenceItemsOnMappings(): void
    {
        $source = <<<'YAML'
            services:
                App\Listener:
                    tags:
                        - name: first
                          event: first.event
                        - name: second
                    calls: [[setLogger, ['@logger']]]
            YAML;
        $mappings = (new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))->parse($source);

        self::assertSame(
            [
                [['services'], []],
                [['services', 'App\Listener'], []],
                [['services', 'App\Listener', 'tags'], []],
                [['services', 'App\Listener', 'tags', 'name'], [[3, 0]]],
                [['services', 'App\Listener', 'tags', 'event'], [[3, 0]]],
                [['services', 'App\Listener', 'tags', 'name'], [[3, 1]]],
                [['services', 'App\Listener', 'calls'], []],
            ],
            array_map(static fn (YamlMapping $mapping): array => [
                $mapping->path,
                array_map(static fn (YamlSequenceItem $item): array => [$item->pathDepth, $item->index], $mapping->sequence),
            ], $mappings),
        );
    }

    public function testProvidesScalarFactsFromTheTree(): void
    {
        $source = self::scalarSource();
        $document = (new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))->parseDocument($source);

        self::assertSame(
            [
                ["value\nA # inside", '"value\\n\\u0041 # inside"', 'double-quoted', ['quoted:key'], [], null, null, 'value\\n\\u0041 # inside'],
                ["it's # content", "'it''s # content'", 'single-quoted', ['single'], [], null, null, "it''s # content"],
                ['first', 'first', 'plain', ['between'], [], null, null, 'first'],
                ['@app.first', "'@app.first'", 'single-quoted', ['sequence'], [[1, 0]], null, '!service', '@app.first'],
                ['plain', 'plain', 'plain', ['sequence'], [[1, 1]], null, null, 'plain'],
                ['nested', '"nested"', 'double-quoted', ['sequence', 'name'], [[1, 2]], null, null, 'nested'],
                ['sequence block', "|-\n    sequence block\n", 'block-literal', ['sequence'], [[1, 3]], null, null, "sequence block\n"],
                ["%env(BLOCK_ONE)% # content\nsecond", "|-\n  %env(BLOCK_ONE)% # content\n  second\n", 'block-literal', ['block'], [], null, null, "%env(BLOCK_ONE)% # content\n  second\n"],
                ["folded value\n", ">\n  folded\n  value\n", 'block-folded', ['folded'], [], null, null, "folded\n  value\n"],
                ['  kept', "|2-\n    kept\n", 'block-literal', ['explicit'], [], null, null, "  kept\n"],
                ["first\n  code\nlast", ">-\n  first\n    code\n  last\n", 'block-folded', ['folded_indented'], [], null, null, "first\n    code\n  last\n"],
                ['@app.foo', "'@app.foo'", 'single-quoted', ['services', 'App\\Foo'], [], 'test', '!service', '@app.foo'],
            ],
            array_map(static fn (YamlScalar $scalar): array => self::scalarData($source, $scalar), $document->scalars),
        );
        foreach ($document->scalars as $scalar) {
            self::assertSame($scalar->raw, substr($source, $scalar->startByte, $scalar->endByte - $scalar->startByte));
        }
        self::assertSame('quoted:key', substr($source, $document->mappings[0]->keyStartByte, $document->mappings[0]->keyEndByte - $document->mappings[0]->keyStartByte));
    }

    public function testMeasuresBlockScalarContentFromTheIndentationOfItsLine(): void
    {
        $parser = new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()));
        $documentStart = "|\n  first\n";
        $nested = "parameters:\r\n    script: |\r\n        second\r\n";

        $first = $parser->parseDocument($documentStart)->scalars[0];
        $second = $parser->parseDocument($nested)->scalars[0];

        self::assertSame((int) strpos($documentStart, 'first'), $first->contentStartByte);
        self::assertSame((int) strpos($nested, 'second'), $second->contentStartByte);
    }

    public function testProvidesYamlTagOffsets(): void
    {
        $source = 'value: !php/enum App\\ResetMode::SCHEMA';
        $scalar = (new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))->parseDocument($source)->scalars[0];
        $tagStart = (int) strpos($source, '!php/enum');

        self::assertSame('!php/enum', $scalar->tag);
        self::assertSame($tagStart, $scalar->tagStartByte);
        self::assertSame($tagStart + \strlen('!php/enum'), $scalar->tagEndByte);
    }

    public function testRecoveryProvidesYamlTagOffsets(): void
    {
        $source = 'value: !php/const PHP_VERSION_ID';
        $scalar = (new YamlDocumentParser(self::errorParser()))->parseDocument($source)->scalars[0];
        $tagStart = (int) strpos($source, '!php/const');

        self::assertSame('!php/const', $scalar->tag);
        self::assertSame($tagStart, $scalar->tagStartByte);
        self::assertSame($tagStart + \strlen('!php/const'), $scalar->tagEndByte);
    }

    public function testParsedScalarFactsKeepTheRecoveredAncestorsOfTheSameRange(): void
    {
        $source = 'broken: [one';
        $recovered = array_values(array_filter(
            (new YamlRecoveryParser())->parse($source)->scalars,
            static fn (YamlScalar $scalar): bool => 'one' === $scalar->raw,
        ))[0];
        $scalar = array_values(array_filter(
            (new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))->parseDocument($source)->scalars,
            static fn (YamlScalar $scalar): bool => 'one' === $scalar->raw,
        ))[0];

        self::assertSame([$recovered->startByte, $recovered->endByte], [$scalar->startByte, $scalar->endByte]);
        self::assertSame(['broken'], $scalar->path);
        self::assertSame([[1, 0]], array_map(static fn (YamlSequenceItem $item): array => [$item->pathDepth, $item->index], $scalar->sequence));
    }

    public function testRejectsRecoveredScalarsThatOverlapParsedFacts(): void
    {
        $parser = new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()));
        $tabbedBlock = "parameters:\n    script: |\n        line\n\tbad: value\n";

        self::assertSame(
            [[(int) strpos($tabbedBlock, '|'), \strlen($tabbedBlock)]],
            array_map(static fn (YamlScalar $scalar): array => [$scalar->startByte, $scalar->endByte], $parser->parseDocument($tabbedBlock)->scalars),
        );

        $quotedLines = "parameters:\n    msg: \"first\n        second\"\n    broken\n";
        $scalars = $parser->parseDocument($quotedLines)->scalars;

        self::assertSame(
            ['first second', 'broken'],
            array_map(static fn (YamlScalar $scalar): string => $scalar->value, $scalars),
        );
        self::assertSame(
            [(int) strpos($quotedLines, '"'), (int) strpos($quotedLines, 'second"') + \strlen('second"')],
            [$scalars[0]->startByte, $scalars[0]->endByte],
        );
    }

    #[DataProvider('flowScalarProvider')]
    public function testFoldsFlowScalarLines(string $raw, YamlScalarStyle $style, string $expected): void
    {
        self::assertSame($expected, (new YamlScalarDecoder())->decode($raw, $style));
    }

    /** @return iterable<string, array{string, YamlScalarStyle, string}> */
    public static function flowScalarProvider(): iterable
    {
        yield 'plain trailing spaces' => ["one  \n  two", YamlScalarStyle::Plain, 'one two'];
        yield 'single quoted trailing spaces and blank line' => ["'one  \n\n  two'", YamlScalarStyle::SingleQuoted, "one\ntwo"];
        yield 'double quoted blank line' => ["\"one\n\n  two\"", YamlScalarStyle::DoubleQuoted, "one\ntwo"];
        yield 'double quoted escaped line break before blank line' => ["\"one\\\n\n  two\"", YamlScalarStyle::DoubleQuoted, "one\ntwo"];
    }

    #[DataProvider('blockScalarProvider')]
    public function testDecodesBlockScalarsLikeSymfonyYaml(string $indicator, string $trailing, string $expected): void
    {
        $source = "message: $indicator\n  one\n  two".$trailing;
        $scalar = (new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))->parseDocument($source)->scalars[0];

        self::assertSame($expected, $scalar->value);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function blockScalarProvider(): iterable
    {
        yield 'literal strip without trailing newline' => ['|-', '', "one\ntwo"];
        yield 'literal strip with one trailing newline' => ['|-', "\n", "one\ntwo"];
        yield 'literal strip with multiple trailing newlines' => ['|-', "\n\n", "one\ntwo"];
        yield 'literal clip without trailing newline' => ['|', '', "one\ntwo"];
        yield 'literal clip with one trailing newline' => ['|', "\n", "one\ntwo\n"];
        yield 'literal clip with multiple trailing newlines' => ['|', "\n\n", "one\ntwo\n"];
        yield 'literal keep without trailing newline' => ['|+', '', "one\ntwo"];
        yield 'literal keep with one trailing newline' => ['|+', "\n", "one\ntwo\n"];
        yield 'literal keep with multiple trailing newlines' => ['|+', "\n\n", "one\ntwo\n\n"];
        yield 'folded strip without trailing newline' => ['>-', '', 'one two'];
        yield 'folded strip with one trailing newline' => ['>-', "\n", 'one two'];
        yield 'folded strip with multiple trailing newlines' => ['>-', "\n\n", 'one two'];
        yield 'folded clip without trailing newline' => ['>', '', 'one two'];
        yield 'folded clip with one trailing newline' => ['>', "\n", "one two\n"];
        yield 'folded clip with multiple trailing newlines' => ['>', "\n\n", "one two\n"];
        yield 'folded keep without trailing newline' => ['>+', '', 'one two'];
        yield 'folded keep with one trailing newline' => ['>+', "\n", "one two\n"];
        yield 'folded keep with multiple trailing newlines' => ['>+', "\n\n", "one two\n\n"];
    }

    #[DataProvider('blockBeforeMappingProvider')]
    public function testPreservesBlockChompingBeforeTheNextMapping(string $indicator, string $expected): void
    {
        $source = "message: $indicator\n  one\n  two\n\nnext: value";
        $scalar = (new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))->parseDocument($source)->scalars[0];

        self::assertSame($expected, $scalar->value);
    }

    /** @return iterable<string, array{string, string}> */
    public static function blockBeforeMappingProvider(): iterable
    {
        yield 'literal clip' => ['|', "one\ntwo\n"];
        yield 'literal keep' => ['|+', "one\ntwo\n\n"];
        yield 'folded clip' => ['>', "one two\n"];
        yield 'folded keep' => ['>+', "one two\n\n"];
    }

    #[DataProvider('foldedBlockProvider')]
    public function testFoldsBlockLinesLikeSymfonyYaml(string $body, string $expected): void
    {
        $source = "message: >-\n".$body;
        $scalar = (new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))->parseDocument($source)->scalars[0];

        self::assertSame($expected, $scalar->value);
    }

    /** @return iterable<string, array{string, string}> */
    public static function foldedBlockProvider(): iterable
    {
        yield 'blank line' => ["  one\n\n  two", "one\ntwo"];
        yield 'two blank lines' => ["  one\n\n\n  two", "one\n\ntwo"];
        yield 'more-indented line' => ["  one\n    code\n  two", "one\n  code\ntwo"];
    }

    public function testRecoveryProducesCompatibleScalarFacts(): void
    {
        $source = self::scalarSource();
        $treeDocument = (new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))->parseDocument($source);
        $recoveredDocument = (new YamlDocumentParser(self::errorParser()))->parseDocument($source);

        self::assertSame(
            array_map(static fn (YamlScalar $scalar): array => self::scalarDataWithOffsets($source, $scalar), $treeDocument->scalars),
            array_map(static fn (YamlScalar $scalar): array => self::scalarDataWithOffsets($source, $scalar), $recoveredDocument->scalars),
        );
    }

    public function testRecoveryKeepsNestedFlowCollectionItemsTogether(): void
    {
        $document = (new YamlDocumentParser(self::errorParser()))->parseDocument('items: [{foo: bar, baz: qux}, final]');

        self::assertSame(['final'], array_map(static fn (YamlScalar $scalar): string => $scalar->raw, $document->scalars));
    }

    public function testRecoversScalarFactsAfterMalformedFlowSyntax(): void
    {
        $source = <<<'YAML'
            broken: [one
            # between
            sequence:
              - !service '@after'
            block: |-
              %env(AFTER)%
            when@dev:
              value: "scoped"
            YAML;
        $document = (new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))->parseDocument($source);

        self::assertSame(
            [
                ['one', ['broken'], [[1, 0]], null, null],
                ["'@after'", ['sequence'], [[1, 0]], null, '!service'],
                ["|-\n  %env(AFTER)%\n", ['block'], [], null, null],
                ['"scoped"', ['value'], [], 'dev', null],
            ],
            array_map(static fn (YamlScalar $scalar): array => [
                $scalar->raw,
                $scalar->path,
                array_map(static fn (YamlSequenceItem $item): array => [$item->pathDepth, $item->index], $scalar->sequence),
                $scalar->environment,
                $scalar->tag,
            ], $document->scalars),
        );
    }

    public function testKeepsTheAncestorsAndEnvironmentOfScalarsBesideAnErrorRegion(): void
    {
        $source = <<<'YAML'
            when@prod:
                services:
                    app.foo:
                        arguments: ['@app.bar']
                        calls:
                            - |
                                text
                    ]broken
            YAML;
        $document = (new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())))->parseDocument($source);

        self::assertSame(
            [
                ['@app.bar', ['services', 'app.foo', 'arguments'], [[3, 0]], 'prod'],
                ["text\n", ['services', 'app.foo', 'calls'], [[3, 0]], 'prod'],
            ],
            array_map(static fn (YamlScalar $scalar): array => [
                $scalar->value,
                $scalar->path,
                array_map(static fn (YamlSequenceItem $item): array => [$item->pathDepth, $item->index], $scalar->sequence),
                $scalar->environment,
            ], $document->scalars),
        );
    }

    private static function scalarSource(): string
    {
        return <<<'YAML'
            "quoted:key": "value\n\u0041 # inside" # outside
            single: 'it''s # content'
            between: first
            # between values
            sequence:
              - !service '@app.first'
              - plain # ignored
              - name: "nested"
              - |-
                sequence block
            block: |-
              %env(BLOCK_ONE)% # content
              second
            folded: >
              folded
              value
            explicit: |2-
                kept
            folded_indented: >-
              first
                code
              last
            when@test:
              services:
                App\Foo: !service '@app.foo'
            YAML;
    }

    /** @return array{string, string, string, list<string>, list<array{int, int}>, string|null, string|null, string} */
    private static function scalarData(string $source, YamlScalar $scalar): array
    {
        return [
            $scalar->value,
            $scalar->raw,
            $scalar->style->value,
            $scalar->path,
            array_map(static fn (YamlSequenceItem $item): array => [$item->pathDepth, $item->index], $scalar->sequence),
            $scalar->environment,
            $scalar->tag,
            substr($source, $scalar->contentStartByte, $scalar->contentEndByte - $scalar->contentStartByte),
        ];
    }

    /** @return array{string, string, string, list<string>, list<array{int, int}>, string|null, string|null, string, int, int, int, int} */
    private static function scalarDataWithOffsets(string $source, YamlScalar $scalar): array
    {
        return [
            ...self::scalarData($source, $scalar),
            $scalar->startByte,
            $scalar->endByte,
            $scalar->contentStartByte,
            $scalar->contentEndByte,
        ];
    }

    private static function errorParser(): TreeSitterParserInterface
    {
        return new class implements TreeSitterParserInterface {
            public function parse(string $language, string $source): TreeSitterTree
            {
                return new TreeSitterTree(true, [new TreeSitterNode('stream', 0, \strlen($source), null, null, false, false, true, [])]);
            }
        };
    }
}
