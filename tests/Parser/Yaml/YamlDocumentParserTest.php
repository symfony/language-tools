<?php

namespace Symfony\Lsp\Tests\Parser\Yaml;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterParserInterface;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterTree;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlMapping;
use Symfony\Lsp\Parser\Yaml\YamlScalar;
use Symfony\Lsp\Parser\Yaml\YamlSequenceItem;

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
                ['sequence block', "|-\n    sequence block", 'block-literal', ['sequence'], [[1, 3]], null, null, 'sequence block'],
                ["%env(BLOCK_ONE)% # content\nsecond", "|-\n  %env(BLOCK_ONE)% # content\n  second", 'block-literal', ['block'], [], null, null, "%env(BLOCK_ONE)% # content\n  second"],
                ["folded value\n", ">\n  folded\n  value", 'block-folded', ['folded'], [], null, null, "folded\n  value"],
                ['  kept', "|2-\n    kept", 'block-literal', ['explicit'], [], null, null, '  kept'],
                ["first\n  code\nlast", ">-\n  first\n    code\n  last", 'block-folded', ['folded_indented'], [], null, null, "first\n    code\n  last"],
                ['@app.foo', "'@app.foo'", 'single-quoted', ['services', 'App\\Foo'], [], 'test', '!service', '@app.foo'],
            ],
            array_map(static fn (YamlScalar $scalar): array => self::scalarData($source, $scalar), $document->scalars),
        );
        foreach ($document->scalars as $scalar) {
            self::assertSame($scalar->raw, substr($source, $scalar->startByte, $scalar->endByte - $scalar->startByte));
        }
        self::assertSame('quoted:key', substr($source, $document->mappings[0]->keyStartByte, $document->mappings[0]->keyEndByte - $document->mappings[0]->keyStartByte));
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
                ["|-\n  %env(AFTER)%", ['block'], [], null, null],
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
