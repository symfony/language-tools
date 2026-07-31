<?php

namespace Symfony\Lsp\Tests\Feature\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Translation\ProjectTranslationSnapshotLoader;
use Symfony\Lsp\Feature\Translation\TranslationIndexRegistry;
use Symfony\Lsp\Project\Project;

final class ProjectTranslationSnapshotLoaderTest extends TestCase
{
    public function testLoadsEffectiveCatalogues(): void
    {
        $indexes = new TranslationIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        (new ProjectTranslationSnapshotLoader($indexes))->load($project, ['sections' => ['translations' => ['complete' => true, 'items' => [
            ['key' => 'article.title', 'domain' => 'messages', 'locale' => 'en', 'message' => 'Article %name%'],
        ]]]]);

        self::assertSame('Article %name%', $indexes->forProject($project)->messages('messages', 'article.title')[0]->message());
        self::assertTrue($indexes->forProject($project)->isComplete());
    }
}
