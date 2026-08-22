<?php

namespace Symfony\Lsp\Tests\Feature\Environment;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Environment\EnvironmentExtractor;
use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Feature\Environment\EnvironmentProvider;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class EnvironmentProviderTest extends TestCase
{
    public function testIndexesNamesAndReferencesWithoutValues(): void
    {
        $extractor = new EnvironmentExtractor(new PositionConverter(), new UriToPathConverter(), new TwigCommentParser(), new PhpCommentParser());
        $facts = $extractor->extract('file:///workspace/.env', 'dotenv', "APP_SECRET=CANARY_SECRET_VALUE\nAPP_URL=https://example.com\nEMPTY=\nCHILD=\${APP_URL:-\${FALLBACK_URL}}/\$EMPTY\nPARTIAL=\${UNFINISHED\nESCAPED=\\\$IGNORED\n");

        self::assertSame(['APP_SECRET', 'APP_URL', 'EMPTY', 'CHILD', 'PARTIAL', 'ESCAPED'], array_map(static fn ($item): string => $item->name(), $facts->declarations()));
        self::assertSame(['APP_URL', 'FALLBACK_URL', 'EMPTY', 'UNFINISHED'], array_map(static fn ($item): string => $item->name(), $facts->references()));
        self::assertTrue($facts->declarations()[2]->hasDefault());
        self::assertStringNotContainsString('CANARY_SECRET_VALUE', implode(' ', array_map(static fn ($item): string => $item->name(), $facts->declarations())));

        $twigFacts = $extractor->extract('file:///workspace/templates/page.html.twig', 'twig', "{## %env(DOCUMENTED_ENV)% #}\n{{ '%env(REAL_ENV)%' }}");
        self::assertSame(['REAL_ENV'], array_map(static fn ($item): string => $item->name(), $twigFacts->references()));
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
        $extractor = new EnvironmentExtractor($converter, new UriToPathConverter(), $commentParser, new PhpCommentParser());
        $indexes = new EnvironmentIndexRegistry();
        $indexes->forProject($project)->replaceSources($extractor->extract('file:///workspace/.env', 'dotenv', "APP_URL=CANARY_SECRET_VALUE\n"), $extractor->extract($uri, 'yaml', $text));
        $indexes->forProject($project)->replaceProcessors(['custom' => 'string', 'json' => 'array']);
        $provider = new EnvironmentProvider(new DocumentContextResolver($documents, $projects), $converter, new LspProtocolMapper(), $indexes, $extractor, $commentParser);
        $position = $converter->toPosition($text, strpos($text, 'APP_UR') + \strlen('APP_UR'));
        $params = ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]];

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
        $commentText = '{## %env(APP_UR) #}';
        $documents->open(new Document($commentUri, 'twig', 1, $commentText));
        $commentPosition = $converter->toPosition($commentText, strpos($commentText, 'APP_UR') + \strlen('APP_UR'));
        self::assertNull($provider->complete(['textDocument' => ['uri' => $commentUri], 'position' => ['line' => $commentPosition->line(), 'character' => $commentPosition->character()]]));
    }

    public function testIgnoresEnvironmentReferencesInPhpComments(): void
    {
        $extractor = new EnvironmentExtractor(new PositionConverter(), new UriToPathConverter(), new TwigCommentParser(), new PhpCommentParser());

        $facts = $extractor->extract('file:///workspace/src/Kernel.php', 'php', <<<'PHP'
            <?php
            // $dsn = '%env(COMMENTED_ENV)%';
            /* uses '%env(BLOCKED_ENV)%' */
            $dsn = '%env(LIVE_ENV)%';
            PHP);

        self::assertSame(['LIVE_ENV'], array_map(static fn ($reference): string => $reference->name(), $facts->references()));
    }
}
