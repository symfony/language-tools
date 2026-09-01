<?php

namespace Symfony\Lsp\Tests\Feature\Environment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Environment\EnvironmentCompletionProvider;
use Symfony\Lsp\Feature\Environment\EnvironmentDiagnosticProvider;
use Symfony\Lsp\Feature\Environment\EnvironmentExtractor;
use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Feature\Environment\EnvironmentProcessorChainValidator;
use Symfony\Lsp\Feature\Environment\EnvironmentRelationshipProvider;
use Symfony\Lsp\Feature\Environment\EnvironmentSymbolResolver;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class EnvironmentProviderTest extends TestCase
{
    public function testIndexesNamesAndReferencesWithoutValues(): void
    {
        $extractor = new EnvironmentExtractor(new PositionConverter(), new UriToPathConverter(), new TwigCommentParser(), new PhpCommentParser(), $this->yamlParser(), new XmlCommentParser());
        $facts = $extractor->extract(new SourceDocument('file:///workspace/.env', 'dotenv', "APP_SECRET=CANARY_SECRET_VALUE\nAPP_URL=https://example.com\nEMPTY=\nCHILD=\${APP_URL:-\${FALLBACK_URL}}/\$EMPTY\nPARTIAL=\${UNFINISHED\nESCAPED=\\\$IGNORED\n"));

        self::assertSame(['APP_SECRET', 'APP_URL', 'EMPTY', 'CHILD', 'PARTIAL', 'ESCAPED'], array_map(static fn ($item): string => $item->name, $facts->declarations));
        self::assertSame(['APP_URL', 'FALLBACK_URL', 'EMPTY', 'UNFINISHED'], array_map(static fn ($item): string => $item->name, $facts->references));
        self::assertTrue($facts->declarations[2]->hasDefault);
        self::assertStringNotContainsString('CANARY_SECRET_VALUE', implode(' ', array_map(static fn ($item): string => $item->name, $facts->declarations)));

        $twigFacts = $extractor->extract(new SourceDocument('file:///workspace/templates/page.html.twig', 'twig', "{## %env(DOCUMENTED_ENV)% #}\n{{ '%env(REAL_ENV)%' }}"));
        self::assertSame(['REAL_ENV'], array_map(static fn ($item): string => $item->name, $twigFacts->references));
    }

    #[DataProvider('yamlScalarContextProvider')]
    public function testSupportsEnvironmentExpressionsInYamlScalarContexts(string $text): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $twigComments = new TwigCommentParser();
        $phpComments = new PhpCommentParser();
        $yamlParser = $this->yamlParser();
        $xmlComments = new XmlCommentParser();
        $extractor = new EnvironmentExtractor($converter, new UriToPathConverter(), $twigComments, $phpComments, $yamlParser, $xmlComments);
        $indexes = new EnvironmentIndexRegistry();
        $indexes->forProject($project)->replaceSources(
            $extractor->extract(new SourceDocument('file:///workspace/.env', 'dotenv', "PARTIAL_ENV=value\n")),
            $extractor->extract(new SourceDocument($uri, 'yaml', $text)),
        );
        [$completionProvider, , $diagnosticProvider] = $this->providers($documents, $projects, $converter, $indexes, $extractor, $twigComments, $phpComments, $yamlParser, $xmlComments);

        $facts = $extractor->extract(new SourceDocument($uri, 'yaml', $text));
        self::assertSame(['COMPLETE_ENV'], array_map(static fn ($reference): string => $reference->name, $facts->references));
        self::assertSame($this->protocolRange($converter, $text, (int) strpos($text, 'COMPLETE_ENV'), \strlen('COMPLETE_ENV')), $this->protocolRangeFromObject($facts->references[0]->range));

        $completionStart = (int) strpos($text, 'PARTIAL_EN');
        $completionOffset = $completionStart + \strlen('PARTIAL_EN');
        $completion = $completionProvider->complete($this->positionParams($converter, $uri, $text, $completionOffset)) ?? [];
        self::assertSame(['PARTIAL_ENV'], array_column($completion, 'label'));
        /** @var array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}} $textEdit */
        $textEdit = $completion[0]['textEdit'];
        self::assertSame($this->protocolRange($converter, $text, $completionStart, \strlen('PARTIAL_EN')), $textEdit['range']);

        $malformed = '%env(MALFORMED_ENV%';
        $malformedOffset = (int) strpos($text, $malformed);
        $diagnostics = $diagnosticProvider->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['env.malformed_chain'], array_column($diagnostics, 'code'));
        self::assertSame($this->protocolRange($converter, $text, $malformedOffset, \strlen($malformed)), $diagnostics[0]['range'] ?? null);
    }

    /** @return iterable<string, array{string}> */
    public static function yamlScalarContextProvider(): iterable
    {
        yield 'double quoted with escapes' => [<<<'YAML'
            complete: "escaped\\n%env(COMPLETE_ENV)%"
            completion: "escaped\\t%env(PARTIAL_EN"
            malformed: "escaped\\u0020%env(MALFORMED_ENV%"
            YAML];
        yield 'block scalar' => [<<<'YAML'
            complete: |-
              %env(COMPLETE_ENV)%
            completion: |-
              %env(PARTIAL_EN
            malformed: |-
              %env(MALFORMED_ENV%
            YAML];
        yield 'block sequence item' => [<<<'YAML'
            values:
              - '%env(COMPLETE_ENV)%'
              - '%env(PARTIAL_EN'
              - '%env(MALFORMED_ENV%'
            YAML];
        yield 'environment section' => [<<<'YAML'
            when@test:
              complete: '%env(COMPLETE_ENV)%'
              completion: '%env(PARTIAL_EN'
              malformed: '%env(MALFORMED_ENV%'
            YAML];
    }

    public function testCompletesHoversNavigatesAndDiagnosesProcessors(): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $text = "dsn: '%env(json:APP_URL)%'\nbad: '%env(unknown:APP_URL)%'\ncustom: '%env(custom:option:APP_URL)%'";
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $commentParser = new TwigCommentParser();
        $phpComments = new PhpCommentParser();
        $yamlParser = $this->yamlParser();
        $xmlComments = new XmlCommentParser();
        $extractor = new EnvironmentExtractor($converter, new UriToPathConverter(), $commentParser, $phpComments, $yamlParser, $xmlComments);
        $indexes = new EnvironmentIndexRegistry();
        $indexes->forProject($project)->replaceSources($extractor->extract(new SourceDocument('file:///workspace/.env', 'dotenv', "APP_URL=CANARY_SECRET_VALUE\n")), $extractor->extract(new SourceDocument($uri, 'yaml', $text)));
        $indexes->forProject($project)->replaceProcessors(['custom' => 'string', 'json' => 'array']);
        [$completionProvider, $relationshipProvider, $diagnosticProvider] = $this->providers($documents, $projects, $converter, $indexes, $extractor, $commentParser, $phpComments, $yamlParser, $xmlComments);
        $position = $converter->toPosition($text, strpos($text, 'APP_UR') + \strlen('APP_UR'));
        $params = ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]];

        $completion = $completionProvider->complete($params) ?? [];
        self::assertSame(['APP_URL'], array_column($completion, 'label'));
        self::assertSame([
            'range' => ['start' => ['line' => 0, 'character' => 16], 'end' => ['line' => 0, 'character' => 23]],
            'newText' => 'APP_URL',
        ], $completion[0]['textEdit'] ?? null);
        $hover = $relationshipProvider->hover($params);
        self::assertIsArray($hover);
        self::assertStringNotContainsString('CANARY_SECRET_VALUE', json_encode($hover, \JSON_THROW_ON_ERROR));
        self::assertSame(['file:///workspace/.env'], array_column($relationshipProvider->definition($params) ?? [], 'uri'));
        self::assertSame(['env.unknown_processor'], array_column($diagnosticProvider->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'));

        $commentUri = 'file:///workspace/templates/comment.html.twig';
        $commentText = "{## %env(APP_UR) %env(APP_URL% #}\n{{ '%env(APP_URL%' }}";
        $documents->open(new Document($commentUri, 'twig', 1, $commentText));
        $commentPosition = $converter->toPosition($commentText, strpos($commentText, 'APP_UR') + \strlen('APP_UR'));
        self::assertNull($completionProvider->complete(['textDocument' => ['uri' => $commentUri], 'position' => ['line' => $commentPosition->line, 'character' => $commentPosition->character]]));
        $malformedOffset = (int) strrpos($commentText, '%env(APP_URL%');
        $diagnostics = $diagnosticProvider->diagnostics(['textDocument' => ['uri' => $commentUri]]) ?? [];
        self::assertSame(['env.malformed_chain'], array_column($diagnostics, 'code'));
        self::assertSame($this->protocolRange($converter, $commentText, $malformedOffset, \strlen('%env(APP_URL%')), $diagnostics[0]['range'] ?? null);
    }

    #[DataProvider('commentedConfigurationProvider')]
    public function testIgnoresCommentedConfigurationAcrossCapabilities(string $languageId, string $text): void
    {
        $uri = 'file:///workspace/config/services.'.$languageId;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, $languageId, 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $twigComments = new TwigCommentParser();
        $phpComments = new PhpCommentParser();
        $yamlParser = $this->yamlParser();
        $xmlComments = new XmlCommentParser();
        $extractor = new EnvironmentExtractor($converter, new UriToPathConverter(), $twigComments, $phpComments, $yamlParser, $xmlComments);
        $indexes = new EnvironmentIndexRegistry();
        $indexes->forProject($project)->replaceSources(
            $extractor->extract(new SourceDocument('file:///workspace/.env', 'dotenv', "APP_URL=value\n")),
            $extractor->extract(new SourceDocument($uri, $languageId, $text)),
        );
        $indexes->forProject($project)->replaceProcessors(['json' => 'array']);
        [$completionProvider, $relationshipProvider, $diagnosticProvider] = $this->providers($documents, $projects, $converter, $indexes, $extractor, $twigComments, $phpComments, $yamlParser, $xmlComments);

        $commentCompletionOffset = strpos($text, 'APP_UR') + \strlen('APP_UR');
        self::assertNull($completionProvider->complete($this->positionParams($converter, $uri, $text, $commentCompletionOffset)));
        $liveNameStart = (int) strrpos($text, 'APP_URL');
        $liveCompletionOffset = $liveNameStart + \strlen('APP_UR');
        $completion = $completionProvider->complete($this->positionParams($converter, $uri, $text, $liveCompletionOffset)) ?? [];
        self::assertSame(['APP_URL'], array_column($completion, 'label'));
        /** @var array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}} $textEdit */
        $textEdit = $completion[0]['textEdit'];
        self::assertSame($this->protocolRange($converter, $text, $liveNameStart, \strlen('APP_URL')), $textEdit['range']);

        $commentHoverOffset = strpos($text, 'unknown:APP_URL') + \strlen('unknown:') + 1;
        self::assertNull($relationshipProvider->hover($this->positionParams($converter, $uri, $text, $commentHoverOffset)));
        self::assertNull($relationshipProvider->definition($this->positionParams($converter, $uri, $text, $commentHoverOffset)));
        $liveOffset = $liveNameStart + 1;
        $liveParams = $this->positionParams($converter, $uri, $text, $liveOffset);
        self::assertIsArray($relationshipProvider->hover($liveParams));
        self::assertSame(['file:///workspace/.env'], array_column($relationshipProvider->definition($liveParams) ?? [], 'uri'));
        $references = $relationshipProvider->references($liveParams) ?? [];
        self::assertSame([$uri], array_column($references, 'uri'));
        /** @var array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}} $reference */
        $reference = $references[0];
        self::assertSame($this->protocolRange($converter, $text, $liveNameStart, \strlen('APP_URL')), $reference['range']);

        $realMalformedOffset = (int) strrpos($text, '%env(APP_URL%');
        $diagnostics = $diagnosticProvider->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['env.malformed_chain'], array_column($diagnostics, 'code'));
        self::assertSame($this->protocolRange($converter, $text, $realMalformedOffset, \strlen('%env(APP_URL%')), $diagnostics[0]['range'] ?? null);
    }

    /** @return iterable<string, array{string, string}> */
    public static function commentedConfigurationProvider(): iterable
    {
        yield 'YAML' => ['yaml', <<<'YAML'
            # hover: '%env(unknown:APP_URL)%'
            # complete: '%env(APP_UR
            # malformed: '%env(APP_URL%'
            broken: '%env(APP_URL%'
            live: '%env(json:APP_URL)%'
            YAML];
        yield 'XML' => ['xml', <<<'XML'
            <container>
                <!-- "<fake attribute='>'>" %env(unknown:APP_URL)% %env(APP_UR
                %env(APP_URL% -->
                <broken>%env(APP_URL%</broken>
                <parameter>%env(json:APP_URL)%</parameter>
            </container>
            XML];
    }

    public function testOffersNoEnvironmentCompletionsInsidePhpComments(): void
    {
        $uri = 'file:///workspace/src/Kernel.php';
        $text = "<?php // \$url = '%env(APP_U %env(APP_URL%'\n\$real = '%env(APP_URL%';";
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $commentParser = new TwigCommentParser();
        $phpComments = new PhpCommentParser();
        $yamlParser = $this->yamlParser();
        $xmlComments = new XmlCommentParser();
        $extractor = new EnvironmentExtractor($converter, new UriToPathConverter(), $commentParser, $phpComments, $yamlParser, $xmlComments);
        $indexes = new EnvironmentIndexRegistry();
        $indexes->forProject($project)->replaceSources($extractor->extract(new SourceDocument('file:///workspace/.env', 'dotenv', "APP_URL=value\n")));
        [$completionProvider, , $diagnosticProvider] = $this->providers($documents, $projects, $converter, $indexes, $extractor, $commentParser, $phpComments, $yamlParser, $xmlComments);
        $completionOffset = strpos($text, 'APP_U') + \strlen('APP_U');
        $position = $converter->toPosition($text, $completionOffset);

        self::assertNull($completionProvider->complete(['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]]));
        $malformedOffset = (int) strrpos($text, '%env(APP_URL%');
        $diagnostics = $diagnosticProvider->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['env.malformed_chain'], array_column($diagnostics, 'code'));
        self::assertSame($this->protocolRange($converter, $text, $malformedOffset, \strlen('%env(APP_URL%')), $diagnostics[0]['range'] ?? null);
    }

    public function testIgnoresEnvironmentReferencesInPhpComments(): void
    {
        $extractor = new EnvironmentExtractor(new PositionConverter(), new UriToPathConverter(), new TwigCommentParser(), new PhpCommentParser(), $this->yamlParser(), new XmlCommentParser());

        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Kernel.php', 'php', <<<'PHP'
            <?php
            // $dsn = '%env(COMMENTED_ENV)%';
            /* uses '%env(BLOCKED_ENV)%' */
            $dsn = '%env(LIVE_ENV)%';
            PHP));

        self::assertSame(['LIVE_ENV'], array_map(static fn ($reference): string => $reference->name, $facts->references));
    }

    /** @return array{EnvironmentCompletionProvider, EnvironmentRelationshipProvider, EnvironmentDiagnosticProvider} */
    private function providers(DocumentStore $documents, ProjectRegistry $projects, PositionConverter $converter, EnvironmentIndexRegistry $indexes, EnvironmentExtractor $extractor, TwigCommentParser $twigComments, PhpCommentParser $phpComments, YamlDocumentParser $yamlParser, XmlCommentParser $xmlComments): array
    {
        $resolver = new DocumentContextResolver($documents, $projects);
        $protocol = new LspProtocolMapper();

        return [
            new EnvironmentCompletionProvider($resolver, $converter, $protocol, $indexes, $twigComments, $phpComments, $yamlParser, $xmlComments),
            new EnvironmentRelationshipProvider($protocol, $indexes, new EnvironmentSymbolResolver($resolver, $converter, $extractor)),
            new EnvironmentDiagnosticProvider($resolver, $converter, $protocol, $indexes, $extractor, new EnvironmentProcessorChainValidator(), $twigComments, $phpComments, $yamlParser, $xmlComments),
        ];
    }

    private function yamlParser(): YamlDocumentParser
    {
        return new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()));
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function positionParams(PositionConverter $converter, string $uri, string $text, int $offset): array
    {
        $position = $converter->toPosition($text, $offset);

        return ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]];
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    private function protocolRangeFromObject(Range $range): array
    {
        return [
            'start' => ['line' => $range->start->line, 'character' => $range->start->character],
            'end' => ['line' => $range->end->line, 'character' => $range->end->character],
        ];
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    private function protocolRange(PositionConverter $converter, string $text, int $offset, int $length): array
    {
        $start = $converter->toPosition($text, $offset);
        $end = $converter->toPosition($text, $offset + $length);

        return [
            'start' => ['line' => $start->line, 'character' => $start->character],
            'end' => ['line' => $end->line, 'character' => $end->character],
        ];
    }
}
