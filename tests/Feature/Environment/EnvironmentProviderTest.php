<?php

namespace Symfony\Lsp\Tests\Feature\Environment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Environment\EnvironmentExtractor;
use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Feature\Environment\EnvironmentProvider;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlCommentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class EnvironmentProviderTest extends TestCase
{
    public function testIndexesNamesAndReferencesWithoutValues(): void
    {
        $extractor = new EnvironmentExtractor(new PositionConverter(), new UriToPathConverter(), new TwigCommentParser(), new PhpCommentParser(), new YamlCommentParser(), new XmlCommentParser());
        $facts = $extractor->extract('file:///workspace/.env', 'dotenv', "APP_SECRET=CANARY_SECRET_VALUE\nAPP_URL=https://example.com\nEMPTY=\nCHILD=\${APP_URL:-\${FALLBACK_URL}}/\$EMPTY\nPARTIAL=\${UNFINISHED\nESCAPED=\\\$IGNORED\n");

        self::assertSame(['APP_SECRET', 'APP_URL', 'EMPTY', 'CHILD', 'PARTIAL', 'ESCAPED'], array_map(static fn ($item): string => $item->name, $facts->declarations));
        self::assertSame(['APP_URL', 'FALLBACK_URL', 'EMPTY', 'UNFINISHED'], array_map(static fn ($item): string => $item->name, $facts->references));
        self::assertTrue($facts->declarations[2]->hasDefault);
        self::assertStringNotContainsString('CANARY_SECRET_VALUE', implode(' ', array_map(static fn ($item): string => $item->name, $facts->declarations)));

        $twigFacts = $extractor->extract('file:///workspace/templates/page.html.twig', 'twig', "{## %env(DOCUMENTED_ENV)% #}\n{{ '%env(REAL_ENV)%' }}");
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
        $yamlComments = new YamlCommentParser();
        $xmlComments = new XmlCommentParser();
        $extractor = new EnvironmentExtractor($converter, new UriToPathConverter(), $twigComments, $phpComments, $yamlComments, $xmlComments);
        $indexes = new EnvironmentIndexRegistry();
        $indexes->forProject($project)->replaceSources(
            $extractor->extract('file:///workspace/.env', 'dotenv', "PARTIAL_ENV=value\n"),
            $extractor->extract($uri, 'yaml', $text),
        );
        $provider = new EnvironmentProvider(new DocumentContextResolver($documents, $projects), $converter, new LspProtocolMapper(), $indexes, $extractor, $twigComments, $phpComments, $yamlComments, $xmlComments);

        $facts = $extractor->extract($uri, 'yaml', $text);
        self::assertSame(['COMPLETE_ENV'], array_map(static fn ($reference): string => $reference->name, $facts->references));
        self::assertSame($this->protocolRange($converter, $text, (int) strpos($text, 'COMPLETE_ENV'), \strlen('COMPLETE_ENV')), $this->protocolRangeFromObject($facts->references[0]->range));

        $completionStart = (int) strpos($text, 'PARTIAL_EN');
        $completionOffset = $completionStart + \strlen('PARTIAL_EN');
        $completion = $provider->complete($this->positionParams($converter, $uri, $text, $completionOffset)) ?? [];
        self::assertSame(['PARTIAL_ENV'], array_column($completion, 'label'));
        /** @var array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}} $textEdit */
        $textEdit = $completion[0]['textEdit'];
        self::assertSame($this->protocolRange($converter, $text, $completionStart, \strlen('PARTIAL_EN')), $textEdit['range']);

        $malformed = '%env(MALFORMED_ENV%';
        $malformedOffset = (int) strpos($text, $malformed);
        $diagnostics = $provider->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
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
        $yamlComments = new YamlCommentParser();
        $xmlComments = new XmlCommentParser();
        $extractor = new EnvironmentExtractor($converter, new UriToPathConverter(), $commentParser, $phpComments, $yamlComments, $xmlComments);
        $indexes = new EnvironmentIndexRegistry();
        $indexes->forProject($project)->replaceSources($extractor->extract('file:///workspace/.env', 'dotenv', "APP_URL=CANARY_SECRET_VALUE\n"), $extractor->extract($uri, 'yaml', $text));
        $indexes->forProject($project)->replaceProcessors(['custom' => 'string', 'json' => 'array']);
        $provider = new EnvironmentProvider(new DocumentContextResolver($documents, $projects), $converter, new LspProtocolMapper(), $indexes, $extractor, $commentParser, $phpComments, $yamlComments, $xmlComments);
        $position = $converter->toPosition($text, strpos($text, 'APP_UR') + \strlen('APP_UR'));
        $params = ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]];

        $completion = $provider->complete($params) ?? [];
        self::assertSame(['APP_URL'], array_column($completion, 'label'));
        self::assertSame([
            'range' => ['start' => ['line' => 0, 'character' => 16], 'end' => ['line' => 0, 'character' => 23]],
            'newText' => 'APP_URL',
        ], $completion[0]['textEdit'] ?? null);
        $hover = $provider->hover($params);
        self::assertIsArray($hover);
        self::assertStringNotContainsString('CANARY_SECRET_VALUE', json_encode($hover, \JSON_THROW_ON_ERROR));
        self::assertSame(['file:///workspace/.env'], array_column($provider->definition($params) ?? [], 'uri'));
        self::assertSame(['env.unknown_processor'], array_column($provider->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'));

        $commentUri = 'file:///workspace/templates/comment.html.twig';
        $commentText = "{## %env(APP_UR) %env(APP_URL% #}\n{{ '%env(APP_URL%' }}";
        $documents->open(new Document($commentUri, 'twig', 1, $commentText));
        $commentPosition = $converter->toPosition($commentText, strpos($commentText, 'APP_UR') + \strlen('APP_UR'));
        self::assertNull($provider->complete(['textDocument' => ['uri' => $commentUri], 'position' => ['line' => $commentPosition->line, 'character' => $commentPosition->character]]));
        $malformedOffset = (int) strrpos($commentText, '%env(APP_URL%');
        $diagnostics = $provider->diagnostics(['textDocument' => ['uri' => $commentUri]]) ?? [];
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
        $yamlComments = new YamlCommentParser();
        $xmlComments = new XmlCommentParser();
        $extractor = new EnvironmentExtractor($converter, new UriToPathConverter(), $twigComments, $phpComments, $yamlComments, $xmlComments);
        $indexes = new EnvironmentIndexRegistry();
        $indexes->forProject($project)->replaceSources(
            $extractor->extract('file:///workspace/.env', 'dotenv', "APP_URL=value\n"),
            $extractor->extract($uri, $languageId, $text),
        );
        $indexes->forProject($project)->replaceProcessors(['json' => 'array']);
        $provider = new EnvironmentProvider(new DocumentContextResolver($documents, $projects), $converter, new LspProtocolMapper(), $indexes, $extractor, $twigComments, $phpComments, $yamlComments, $xmlComments);

        $commentCompletionOffset = strpos($text, 'APP_UR') + \strlen('APP_UR');
        self::assertNull($provider->complete($this->positionParams($converter, $uri, $text, $commentCompletionOffset)));
        $liveNameStart = (int) strrpos($text, 'APP_URL');
        $liveCompletionOffset = $liveNameStart + \strlen('APP_UR');
        $completion = $provider->complete($this->positionParams($converter, $uri, $text, $liveCompletionOffset)) ?? [];
        self::assertSame(['APP_URL'], array_column($completion, 'label'));
        /** @var array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}} $textEdit */
        $textEdit = $completion[0]['textEdit'];
        self::assertSame($this->protocolRange($converter, $text, $liveNameStart, \strlen('APP_URL')), $textEdit['range']);

        $commentHoverOffset = strpos($text, 'unknown:APP_URL') + \strlen('unknown:') + 1;
        self::assertNull($provider->hover($this->positionParams($converter, $uri, $text, $commentHoverOffset)));
        self::assertNull($provider->definition($this->positionParams($converter, $uri, $text, $commentHoverOffset)));
        $liveOffset = $liveNameStart + 1;
        $liveParams = $this->positionParams($converter, $uri, $text, $liveOffset);
        self::assertIsArray($provider->hover($liveParams));
        self::assertSame(['file:///workspace/.env'], array_column($provider->definition($liveParams) ?? [], 'uri'));
        $references = $provider->references($liveParams) ?? [];
        self::assertSame([$uri], array_column($references, 'uri'));
        /** @var array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}} $reference */
        $reference = $references[0];
        self::assertSame($this->protocolRange($converter, $text, $liveNameStart, \strlen('APP_URL')), $reference['range']);

        $realMalformedOffset = (int) strrpos($text, '%env(APP_URL%');
        $diagnostics = $provider->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
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
        $yamlComments = new YamlCommentParser();
        $xmlComments = new XmlCommentParser();
        $extractor = new EnvironmentExtractor($converter, new UriToPathConverter(), $commentParser, $phpComments, $yamlComments, $xmlComments);
        $indexes = new EnvironmentIndexRegistry();
        $indexes->forProject($project)->replaceSources($extractor->extract('file:///workspace/.env', 'dotenv', "APP_URL=value\n"));
        $provider = new EnvironmentProvider(new DocumentContextResolver($documents, $projects), $converter, new LspProtocolMapper(), $indexes, $extractor, $commentParser, $phpComments, $yamlComments, $xmlComments);
        $completionOffset = strpos($text, 'APP_U') + \strlen('APP_U');
        $position = $converter->toPosition($text, $completionOffset);

        self::assertNull($provider->complete(['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]]));
        $malformedOffset = (int) strrpos($text, '%env(APP_URL%');
        $diagnostics = $provider->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['env.malformed_chain'], array_column($diagnostics, 'code'));
        self::assertSame($this->protocolRange($converter, $text, $malformedOffset, \strlen('%env(APP_URL%')), $diagnostics[0]['range'] ?? null);
    }

    public function testIgnoresEnvironmentReferencesInPhpComments(): void
    {
        $extractor = new EnvironmentExtractor(new PositionConverter(), new UriToPathConverter(), new TwigCommentParser(), new PhpCommentParser(), new YamlCommentParser(), new XmlCommentParser());

        $facts = $extractor->extract('file:///workspace/src/Kernel.php', 'php', <<<'PHP'
            <?php
            // $dsn = '%env(COMMENTED_ENV)%';
            /* uses '%env(BLOCKED_ENV)%' */
            $dsn = '%env(LIVE_ENV)%';
            PHP);

        self::assertSame(['LIVE_ENV'], array_map(static fn ($reference): string => $reference->name, $facts->references));
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
