<?php

namespace Symfony\Lsp\Tests\Feature\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Translation\TranslationCodeActionProvider;
use Symfony\Lsp\Feature\Translation\TranslationConfigurationRegistry;
use Symfony\Lsp\Feature\Translation\TranslationExtractor;
use Symfony\Lsp\Feature\Translation\TranslationIndexRegistry;
use Symfony\Lsp\Feature\Translation\TranslationMessage;
use Symfony\Lsp\Feature\Translation\TranslationProvider;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

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
        $extractor = new TranslationExtractor($converter);
        $indexes = new TranslationIndexRegistry();
        $indexes->forProject($project)->replaceRuntime(true);
        $indexes->forProject($project)->replaceSources($extractor->extract('file://'.$translationPath, 'yaml', "existing: Existing\n"));
        $configuration = new TranslationConfigurationRegistry();
        $configuration->configure($project, true);
        $provider = new TranslationProvider(new DocumentContextResolver($documents, $projects), $documents, $projects, $converter, $indexes, $extractor, $configuration);

        try {
            $diagnostics = $provider->diagnostics(['textDocument' => ['uri' => $uri]]);
            self::assertIsArray($diagnostics);
            $actions = (new TranslationCodeActionProvider($documents, $projects, $converter, $extractor, $indexes))->actions([
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
            self::assertIsArray($action['edit']['documentChanges'][0]['edits'] ?? null);
            self::assertIsArray($action['edit']['documentChanges'][0]['edits'][0]);
            self::assertSame("'missing.key': 'missing.key'\n", $action['edit']['documentChanges'][0]['edits'][0]['newText'] ?? null);
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

    /** @return array{TranslationProvider, PositionConverter, TranslationConfigurationRegistry, Project} */
    private function provider(string $uri, string $text): array
    {
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $extractor = new TranslationExtractor($converter);
        $indexes = new TranslationIndexRegistry();
        $indexes->forProject($project)->replaceRuntime(true, new TranslationMessage('article.title', 'messages', 'en', 'Article %name%'));
        $configuration = new TranslationConfigurationRegistry();

        return [new TranslationProvider(new DocumentContextResolver($documents, $projects), $documents, $projects, $converter, $indexes, $extractor, $configuration), $converter, $configuration, $project];
    }
}
