<?php

namespace Symfony\Lsp\Tests\Feature\Environment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Environment\EnvironmentCompletionProvider;
use Symfony\Lsp\Feature\Environment\EnvironmentDiagnosticProvider;
use Symfony\Lsp\Feature\Environment\EnvironmentExtractor;
use Symfony\Lsp\Feature\Environment\EnvironmentRelationshipProvider;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class EnvironmentProviderTest extends TestCase
{
    private const DOTENV_URI = 'file:///workspace/.env';

    public function testIndexesNamesAndReferencesWithoutValues(): void
    {
        $extractor = $this->extractor();
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
        $kit = $this->kit("PARTIAL_ENV=value\n", $uri, $text);
        $completionProvider = $kit->get(EnvironmentCompletionProvider::class);

        $facts = $kit->get(EnvironmentExtractor::class)->extract(new SourceDocument($uri, 'yaml', $text));
        self::assertSame(['COMPLETE_ENV'], array_map(static fn ($reference): string => $reference->name, $facts->references));
        self::assertSame($this->protocolRange($kit, $uri, (int) strpos($text, 'COMPLETE_ENV'), \strlen('COMPLETE_ENV')), $this->protocolRangeFromObject($facts->references[0]->range));

        $completionStart = (int) strpos($text, 'PARTIAL_EN');
        $completion = $completionProvider->complete($kit->positioned($kit->after($uri, 'PARTIAL_EN')));
        self::assertSame(['PARTIAL_ENV'], $kit->labels($completion));
        /** @var array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}} $textEdit */
        $textEdit = $completion[0]['textEdit'];
        self::assertSame($this->protocolRange($kit, $uri, $completionStart, \strlen('PARTIAL_EN')), $textEdit['range']);

        $malformed = '%env(MALFORMED_ENV%';
        $diagnostics = $kit->get(EnvironmentDiagnosticProvider::class)->diagnostics($kit->document($uri));
        self::assertSame(['env.malformed_chain'], $kit->codes($diagnostics));
        self::assertSame($this->protocolRange($kit, $uri, (int) strpos($text, $malformed), \strlen($malformed)), $diagnostics[0]['range'] ?? null);
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
        $kit = $this->kit("APP_URL=CANARY_SECRET_VALUE\n", $uri, $text, ['custom' => 'string', 'json' => 'array']);
        $completionProvider = $kit->get(EnvironmentCompletionProvider::class);
        $relationshipProvider = $kit->get(EnvironmentRelationshipProvider::class);
        $diagnosticProvider = $kit->get(EnvironmentDiagnosticProvider::class);
        $params = $kit->after($uri, 'APP_UR');

        $completion = $completionProvider->complete($kit->positioned($params));
        self::assertSame(['APP_URL'], $kit->labels($completion));
        self::assertSame([
            'range' => ['start' => ['line' => 0, 'character' => 16], 'end' => ['line' => 0, 'character' => 23]],
            'newText' => 'APP_URL',
        ], $completion[0]['textEdit'] ?? null);
        $hover = $relationshipProvider->hover($kit->positioned($params));
        self::assertIsArray($hover);
        self::assertStringNotContainsString('CANARY_SECRET_VALUE', json_encode($hover, \JSON_THROW_ON_ERROR));
        self::assertSame([self::DOTENV_URI], $kit->targets($relationshipProvider->definition($kit->positioned($params))));
        self::assertSame(['env.unknown_processor'], $kit->codes($diagnosticProvider->diagnostics($kit->document($uri))));

        $commentUri = 'file:///workspace/templates/comment.html.twig';
        $commentText = "{## %env(APP_UR) %env(APP_URL% #}\n{{ '%env(APP_URL%' }}";
        $kit->open($commentUri, $commentText)->index();
        self::assertSame([], $completionProvider->complete($kit->positioned($kit->after($commentUri, 'APP_UR'))));
        $diagnostics = $diagnosticProvider->diagnostics($kit->document($commentUri));
        self::assertSame(['env.malformed_chain'], $kit->codes($diagnostics));
        self::assertSame($this->protocolRange($kit, $commentUri, (int) strrpos($commentText, '%env(APP_URL%'), \strlen('%env(APP_URL%')), $diagnostics[0]['range'] ?? null);
    }

    #[DataProvider('commentedConfigurationProvider')]
    public function testIgnoresCommentedConfigurationAcrossCapabilities(string $languageId, string $text): void
    {
        $uri = 'file:///workspace/config/services.'.$languageId;
        $kit = $this->kit("APP_URL=value\n", $uri, $text, ['json' => 'array']);
        $completionProvider = $kit->get(EnvironmentCompletionProvider::class);
        $relationshipProvider = $kit->get(EnvironmentRelationshipProvider::class);

        self::assertSame([], $completionProvider->complete($kit->positioned($kit->after($uri, 'APP_UR'))));
        $liveNameStart = (int) strrpos($text, 'APP_URL');
        $completion = $completionProvider->complete($kit->positioned($kit->offset($uri, $liveNameStart + \strlen('APP_UR'))));
        self::assertSame(['APP_URL'], $kit->labels($completion));
        /** @var array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}} $textEdit */
        $textEdit = $completion[0]['textEdit'];
        self::assertSame($this->protocolRange($kit, $uri, $liveNameStart, \strlen('APP_URL')), $textEdit['range']);

        $commentParams = $kit->offset($uri, strpos($text, 'unknown:APP_URL') + \strlen('unknown:') + 1);
        self::assertNull($relationshipProvider->hover($kit->positioned($commentParams)));
        self::assertSame([], $relationshipProvider->definition($kit->positioned($commentParams)));
        $liveParams = $kit->offset($uri, $liveNameStart + 1);
        self::assertIsArray($relationshipProvider->hover($kit->positioned($liveParams)));
        self::assertSame([self::DOTENV_URI], $kit->targets($relationshipProvider->definition($kit->positioned($liveParams))));
        self::assertSame([$uri, self::DOTENV_URI], $kit->targets($relationshipProvider->references($kit->references($liveParams))));
        $references = $relationshipProvider->references($kit->references($liveParams, false));
        self::assertSame([$uri], $kit->targets($references));
        /** @var array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}} $reference */
        $reference = $references[0];
        self::assertSame($this->protocolRange($kit, $uri, $liveNameStart, \strlen('APP_URL')), $reference['range']);

        $diagnostics = $kit->get(EnvironmentDiagnosticProvider::class)->diagnostics($kit->document($uri));
        self::assertSame(['env.malformed_chain'], $kit->codes($diagnostics));
        self::assertSame($this->protocolRange($kit, $uri, (int) strrpos($text, '%env(APP_URL%'), \strlen('%env(APP_URL%')), $diagnostics[0]['range'] ?? null);
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
        $kit = $this->kit("APP_URL=value\n", $uri, $text);

        self::assertSame([], $kit->get(EnvironmentCompletionProvider::class)->complete($kit->positioned($kit->after($uri, 'APP_U'))));
        $diagnostics = $kit->get(EnvironmentDiagnosticProvider::class)->diagnostics($kit->document($uri));
        self::assertSame(['env.malformed_chain'], $kit->codes($diagnostics));
        self::assertSame($this->protocolRange($kit, $uri, (int) strrpos($text, '%env(APP_URL%'), \strlen('%env(APP_URL%')), $diagnostics[0]['range'] ?? null);
    }

    public function testIgnoresEnvironmentReferencesInPhpComments(): void
    {
        $extractor = $this->extractor();

        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Kernel.php', 'php', <<<'PHP'
            <?php
            // $dsn = '%env(COMMENTED_ENV)%';
            /* uses '%env(BLOCKED_ENV)%' */
            $dsn = '%env(LIVE_ENV)%';
            PHP));

        self::assertSame(['LIVE_ENV'], array_map(static fn ($reference): string => $reference->name, $facts->references));
    }

    public function testTreatsDoubledPercentSignsAsEscapes(): void
    {
        $kit = new ProjectTestKit();
        $converter = $kit->get(PositionConverter::class);
        $extractor = $kit->get(EnvironmentExtractor::class);
        $php = <<<'PHP'
            <?php
            $container->setParameter('mautic.url', sprintf('%%env(%sresolve:MAUTIC_%s)%%', $type, strtoupper($key)));
            $escaped = '%%env(ESCAPED_ENV)%%';
            $chained = '%kernel.project_dir%%env(LIVE_ENV)%';
            $broken = '100%% %env(BROKEN_ENV%';
            $spaced = '%env(SPACED ENV%';
            PHP;

        $facts = $extractor->extract(new SourceDocument('file:///workspace/src/Kernel.php', 'php', $php));

        self::assertSame(['LIVE_ENV'], array_map(static fn ($reference): string => $reference->name, $facts->references));
        self::assertCount(1, $facts->malformedExpressions);
        self::assertEquals(
            $converter->toRange($php, (int) strpos($php, '%env(BROKEN_ENV%'), \strlen('%env(BROKEN_ENV%')),
            $facts->malformedExpressions[0]->range,
        );

        $yamlFacts = $extractor->extract(new SourceDocument('file:///workspace/config/services.yaml', 'yaml', <<<'YAML'
            escaped: '%%env(ESCAPED_ENV)%%'
            chained: '%kernel.project_dir%%env(LIVE_ENV)%'
            YAML));

        self::assertSame(['LIVE_ENV'], array_map(static fn ($reference): string => $reference->name, $yamlFacts->references));
        self::assertSame([], $yamlFacts->malformedExpressions);
    }

    /** @param array<string, string>|null $processors */
    private function kit(string $dotenv, string $uri, string $text, ?array $processors = null): ProjectTestKit
    {
        $kit = (new ProjectTestKit())
            ->open(self::DOTENV_URI, $dotenv, 'dotenv')
            ->open($uri, $text)
            ->index()
        ;
        if (null !== $processors) {
            $kit->runtime('environment', ['complete' => true, 'processors' => array_map(static fn (string $name, string $type): array => ['name' => $name, 'type' => $type], array_keys($processors), $processors)]);
        }

        return $kit;
    }

    private function extractor(): EnvironmentExtractor
    {
        return (new ProjectTestKit())->get(EnvironmentExtractor::class);
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
    private function protocolRange(ProjectTestKit $kit, string $uri, int $offset, int $length): array
    {
        return ['start' => $kit->offset($uri, $offset)['position'], 'end' => $kit->offset($uri, $offset + $length)['position']];
    }
}
