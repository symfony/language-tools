<?php

namespace Symfony\Lsp\Tests\Feature\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\ProjectDocumentReader;
use Symfony\Lsp\Feature\Translation\TranslationCodeActionProvider;
use Symfony\Lsp\Feature\Translation\TranslationConfigurationRegistry;
use Symfony\Lsp\Feature\Translation\TranslationExtractor;
use Symfony\Lsp\Feature\Translation\TranslationIndexRegistry;
use Symfony\Lsp\Feature\Translation\TranslationMessage;
use Symfony\Lsp\Feature\Translation\TranslationProvider;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\QuotedArgumentMatcher;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TranslationProviderTest extends TestCase
{
    public function testCompletesAndHoversEffectiveTranslationKeys(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = "<?php \$translator->trans('article.ti');";
        [$provider, $converter] = $this->provider($uri, $text);
        $position = $converter->toPosition($text, strpos($text, 'article.ti') + \strlen('article.ti'));
        $params = ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]];

        self::assertSame(['article.title'], array_column($provider->complete($params) ?? [], 'label'));

        $fullText = "<?php \$translator->trans('article.title', ['%name%' => \$name]);";
        [$fullProvider, $fullConverter] = $this->provider($uri, $fullText);
        $fullPosition = $fullConverter->toPosition($fullText, strpos($fullText, 'article.title') + 1);
        $hover = $fullProvider->hover(['textDocument' => ['uri' => $uri], 'position' => ['line' => $fullPosition->line(), 'character' => $fullPosition->character()]]);
        self::assertIsArray($hover);
        self::assertIsArray($hover['contents']);
        self::assertIsString($hover['contents']['value']);
        self::assertStringContainsString('Article %name%', $hover['contents']['value']);
    }

    public function testCompletesMessagePlaceholders(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = "<?php \$translator->trans('article.title', ['%na']);";
        [$provider, $converter] = $this->provider($uri, $text);
        $position = $converter->toPosition($text, strpos($text, '%na') + \strlen('%na'));

        $result = $provider->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ]);
        self::assertIsArray($result);
        self::assertIsArray($result[0]['textEdit']);
        self::assertSame('name', $result[0]['label']);
        self::assertSame('name%', $result[0]['textEdit']['newText']);
    }

    public function testIgnoresCompletionInsideTwigDocumentationComments(): void
    {
        $uri = 'file:///workspace/templates/page.html.twig';
        $text = "{## Use t('article.ti') in examples. #}";
        [$provider, $converter] = $this->provider($uri, $text, 'twig');
        $position = $converter->toPosition($text, strpos($text, 'article.ti') + \strlen('article.ti'));

        self::assertNull($provider->complete(['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]]));
    }

    public function testAddsMissingTranslationToTheOnlyDomainResource(): void
    {
        $root = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($root.'/translations', 0777, true);
        $translationPath = $root.'/translations/messages.en.yaml';
        file_put_contents($translationPath, "existing: Existing\n");
        $uri = 'file://'.$root.'/src/Controller.php';
        $text = "<?php \$translator->trans('missing.key');";
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project($root, 'file://'.$root, '^8.0')]);
        $converter = new PositionConverter();
        $commentParser = new TwigCommentParser();
        $extractor = new TranslationExtractor($converter, new UriToPathConverter(), $commentParser, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())), new QuotedArgumentMatcher($converter), new PhpCommentParser());
        $indexes = new TranslationIndexRegistry();
        $indexes->forProject($project)->replaceRuntime(true);
        $indexes->forProject($project)->replaceSources($extractor->extract('file://'.$translationPath, 'yaml', "existing: Existing\n"));
        $configuration = new TranslationConfigurationRegistry();
        $configuration->configure($project, true);
        $provider = new TranslationProvider(new DocumentContextResolver($documents, $projects), $converter, new LspProtocolMapper(), $indexes, $extractor, $configuration, $commentParser, new PhpCommentParser());

        try {
            $diagnostics = $provider->diagnostics(['textDocument' => ['uri' => $uri]]);
            self::assertIsArray($diagnostics);
            $pathResolver = new ProjectPathResolver(new UriToPathConverter());
            $actions = (new TranslationCodeActionProvider(new DocumentContextResolver($documents, $projects), $converter, new LspProtocolMapper(), $extractor, $indexes, new UriToPathConverter(), $pathResolver, new ProjectDocumentReader($documents, $pathResolver)))->actions([
                'textDocument' => ['uri' => $uri],
                'range' => $diagnostics[0]['range'],
                'context' => ['diagnostics' => $diagnostics],
            ]);

            self::assertIsArray($actions);
            self::assertCount(1, $actions);
            $action = $actions[0];
            self::assertSame('Add translation "missing.key" to messages.en.yaml', $action['title'] ?? null);
            self::assertIsArray($action['edit'] ?? null);
            self::assertIsArray($action['edit']['documentChanges'] ?? null);
            self::assertIsArray($action['edit']['documentChanges'][0]);
            self::assertIsArray($action['edit']['documentChanges'][0]['textDocument'] ?? null);
            self::assertArrayHasKey('version', $action['edit']['documentChanges'][0]['textDocument']);
            self::assertNull($action['edit']['documentChanges'][0]['textDocument']['version']);
            self::assertIsArray($action['edit']['documentChanges'][0]['edits'] ?? null);
            self::assertIsArray($action['edit']['documentChanges'][0]['edits'][0]);
            self::assertSame("'missing.key': 'missing.key'\n", $action['edit']['documentChanges'][0]['edits'][0]['newText'] ?? null);
            self::assertIsArray($action['edit']['documentChanges'][0]['edits'][0]['range'] ?? null);
            self::assertIsArray($action['edit']['documentChanges'][0]['edits'][0]['range']['start'] ?? null);
            self::assertSame(1, $action['edit']['documentChanges'][0]['edits'][0]['range']['start']['line'] ?? null);
        } finally {
            @unlink($translationPath);
            @rmdir($root.'/translations');
            @rmdir($root);
        }
    }

    public function testComputesTheInsertionPointFromTheOpenUnsavedTranslationTarget(): void
    {
        $root = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($root.'/translations', 0777, true);
        $translationPath = $root.'/translations/messages.en.yaml';
        file_put_contents($translationPath, "existing: Existing\n");
        $uri = 'file://'.$root.'/src/Controller.php';
        $text = "<?php \$translator->trans('missing.key');";
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $documents->open(new Document('file://'.$translationPath, 'yaml', 7, "existing: Existing\nunsaved: Unsaved\n"));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project($root, 'file://'.$root, '^8.0')]);
        $converter = new PositionConverter();
        $commentParser = new TwigCommentParser();
        $extractor = new TranslationExtractor($converter, new UriToPathConverter(), $commentParser, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())), new QuotedArgumentMatcher($converter), new PhpCommentParser());
        $indexes = new TranslationIndexRegistry();
        $indexes->forProject($project)->replaceRuntime(true);
        $indexes->forProject($project)->replaceSources($extractor->extract('file://'.$translationPath, 'yaml', "existing: Existing\n"));
        $configuration = new TranslationConfigurationRegistry();
        $configuration->configure($project, true);
        $provider = new TranslationProvider(new DocumentContextResolver($documents, $projects), $converter, new LspProtocolMapper(), $indexes, $extractor, $configuration, $commentParser, new PhpCommentParser());

        try {
            $diagnostics = $provider->diagnostics(['textDocument' => ['uri' => $uri]]);
            self::assertIsArray($diagnostics);
            $pathResolver = new ProjectPathResolver(new UriToPathConverter());
            $actions = (new TranslationCodeActionProvider(new DocumentContextResolver($documents, $projects), $converter, new LspProtocolMapper(), $extractor, $indexes, new UriToPathConverter(), $pathResolver, new ProjectDocumentReader($documents, $pathResolver)))->actions([
                'textDocument' => ['uri' => $uri],
                'range' => $diagnostics[0]['range'],
                'context' => ['diagnostics' => $diagnostics],
            ]);

            self::assertIsArray($actions);
            self::assertCount(1, $actions);
            self::assertIsArray($actions[0]['edit'] ?? null);
            self::assertIsArray($actions[0]['edit']['documentChanges'] ?? null);
            self::assertIsArray($actions[0]['edit']['documentChanges'][0]);
            $change = $actions[0]['edit']['documentChanges'][0];
            self::assertIsArray($change['textDocument'] ?? null);
            self::assertSame(7, $change['textDocument']['version'] ?? null);
            self::assertIsArray($change['edits'] ?? null);
            self::assertIsArray($change['edits'][0]);
            self::assertIsArray($change['edits'][0]['range'] ?? null);
            self::assertIsArray($change['edits'][0]['range']['start'] ?? null);
            self::assertSame(2, $change['edits'][0]['range']['start']['line'] ?? null);
        } finally {
            @unlink($translationPath);
            @rmdir($root.'/translations');
            @rmdir($root);
        }
    }

    public function testMissingDiagnosticsAreProjectScopedAndOptIn(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = "<?php \$translator->trans('missing.key');";
        [$provider, , $configuration, $project] = $this->provider($uri, $text);
        self::assertSame([], $provider->diagnostics(['textDocument' => ['uri' => $uri]]));
        $configuration->configure($project, true);
        self::assertSame(['translation.not_found'], array_column($provider->diagnostics(['textDocument' => ['uri' => $uri]]), 'code'));
    }

    public function testFlagsOnlyMissingPlaceholdersFromLiteralParameters(): void
    {
        $uri = 'file:///workspace/templates/article.html.twig';
        $text = "{{ 'article.title'|trans({name: article.name, extra: 1}) }}\n{{ 'article.title'|trans({extra: 1}) }}\n{{ 'article.title'|trans(params) }}\n";
        [$provider] = $this->provider($uri, $text, 'twig');

        $diagnostics = $provider->diagnostics(['textDocument' => ['uri' => $uri]]);

        self::assertIsArray($diagnostics);
        self::assertSame(['translation.placeholders'], array_column($diagnostics, 'code'));
        /** @var array{start: array{line: int}} $range */
        $range = $diagnostics[0]['range'];
        self::assertSame(1, $range['start']['line']);
    }

    public function testOffersNoTranslationCompletionsInsidePhpComments(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = "<?php // \$translator->trans('article.ti";
        [$provider, $converter] = $this->provider($uri, $text);
        $position = $converter->toPosition($text, strpos($text, 'article.ti') + \strlen('article.ti'));

        self::assertNull($provider->complete(['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]]));
    }

    /** @return array{TranslationProvider, PositionConverter, TranslationConfigurationRegistry, Project} */
    private function provider(string $uri, string $text, string $languageId = 'php'): array
    {
        $documents = new DocumentStore();
        $documents->open(new Document($uri, $languageId, 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $commentParser = new TwigCommentParser();
        $extractor = new TranslationExtractor($converter, new UriToPathConverter(), $commentParser, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())), new QuotedArgumentMatcher($converter), new PhpCommentParser());
        $indexes = new TranslationIndexRegistry();
        $indexes->forProject($project)->replaceRuntime(true, new TranslationMessage('article.title', 'messages', 'en', 'Article %name%'));
        $configuration = new TranslationConfigurationRegistry();

        return [new TranslationProvider(new DocumentContextResolver($documents, $projects), $converter, new LspProtocolMapper(), $indexes, $extractor, $configuration, $commentParser, new PhpCommentParser()), $converter, $configuration, $project];
    }
}
