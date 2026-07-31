<?php

namespace Symfony\Lsp\Tests\Feature\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Translation\TranslationExtractor;

final class TranslationExtractorTest extends TestCase
{
    public function testExtractsNestedYamlMessagesAndPhpReferences(): void
    {
        $extractor = new TranslationExtractor(new PositionConverter());
        $facts = $extractor->extract('file:///workspace/translations/messages.en.yaml', 'yaml', <<<'YAML'
            article:
                title: 'Article %name%'
            YAML);
        $references = $extractor->extract('file:///workspace/src/Controller.php', 'php', <<<'PHP'
            <?php
            $translator->trans('article.title', ['%name%' => $name]);
            $translator->trans('admin.title', [], 'admin');
            PHP);

        self::assertSame(['article.title'], array_map(static fn ($item): string => $item->key(), $facts->declarations()));
        self::assertSame(['name'], $facts->declarations()[0]->placeholders());
        self::assertSame([
            ['article.title', 'messages', ['name']],
            ['admin.title', 'admin', []],
        ], array_map(static fn ($item): array => [$item->key(), $item->domain(), $item->placeholders()], $references->references()));
    }

    public function testExtractsJsonXliffAndPhpResources(): void
    {
        $extractor = new TranslationExtractor(new PositionConverter());
        $json = $extractor->extract('file:///workspace/translations/admin.fr.json', 'json', '{"dashboard":{"title":"Administration"}}');
        $xliff = $extractor->extract('file:///workspace/translations/validators.en.xlf', 'xml', '<xliff><file><body><trans-unit id="1" resname="required"><source>required</source><target>Required</target></trans-unit></body></file></xliff>');
        $php = $extractor->extract('file:///workspace/translations/messages.de.php', 'php', "<?php return ['hello' => 'Hallo'];");

        self::assertSame('dashboard.title', $json->declarations()[0]->key());
        self::assertSame('required', $xliff->declarations()[0]->key());
        self::assertSame('hello', $php->declarations()[0]->key());
    }
}
