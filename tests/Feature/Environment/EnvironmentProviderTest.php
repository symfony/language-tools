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
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

final class EnvironmentProviderTest extends TestCase
{
    public function testIndexesNamesAndReferencesWithoutValues(): void
    {
        $extractor = new EnvironmentExtractor(new PositionConverter(), new UriToPathConverter());
        $facts = $extractor->extract('file:///workspace/.env', 'dotenv', "APP_SECRET=CANARY_SECRET_VALUE\nAPP_URL=https://example.com\nEMPTY=\nCHILD=\${APP_URL:-\${FALLBACK_URL}}/\$EMPTY\nPARTIAL=\${UNFINISHED\nESCAPED=\\\$IGNORED\n");

        self::assertSame(['APP_SECRET', 'APP_URL', 'EMPTY', 'CHILD', 'PARTIAL', 'ESCAPED'], array_map(static fn ($item): string => $item->name(), $facts->declarations()));
        self::assertSame(['APP_URL', 'FALLBACK_URL', 'EMPTY', 'UNFINISHED'], array_map(static fn ($item): string => $item->name(), $facts->references()));
        self::assertTrue($facts->declarations()[2]->hasDefault());
        self::assertStringNotContainsString('CANARY_SECRET_VALUE', implode(' ', array_map(static fn ($item): string => $item->name(), $facts->declarations())));
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
        $extractor = new EnvironmentExtractor($converter, new UriToPathConverter());
        $indexes = new EnvironmentIndexRegistry();
        $indexes->forProject($project)->replaceSources($extractor->extract('file:///workspace/.env', 'dotenv', "APP_URL=CANARY_SECRET_VALUE\n"), $extractor->extract($uri, 'yaml', $text));
        $indexes->forProject($project)->replaceProcessors(['custom' => 'string', 'json' => 'array']);
        $provider = new EnvironmentProvider(new DocumentContextResolver($documents, $projects), $documents, $projects, $converter, $indexes, $extractor);
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
    }
}
