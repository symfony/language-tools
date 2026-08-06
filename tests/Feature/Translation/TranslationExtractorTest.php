<?php

namespace Symfony\Lsp\Tests\Feature\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Translation\TranslationExtractor;
use Symfony\Lsp\Project\UriToPathConverter;

final class TranslationExtractorTest extends TestCase
{
    public function testExtractsNestedYamlMessagesAndPhpReferences(): void
    {
        $extractor = $this->extractor();
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

    public function testToleratesIncompleteJsonAndXliffResources(): void
    {
        $extractor = $this->extractor();
        $json = $extractor->extract('file:///workspace/translations/admin.fr.json', 'json', <<<'JSON'
            {
                "dashboard": {
                    "title": "Administration",
                    "subtitle": "Welcome
            JSON);
        $xliff = $extractor->extract('file:///workspace/translations/validators.en.xlf', 'xml', <<<'XLIFF'
            <xliff xmlns="urn:oasis:names:tc:xliff:document:2.0" version="2.0">
                <file id="messages">
                    <unit id="generated-id" name="required">
                        <segment><source>required</source><target>Required</target></segment>
                    </unit>
                    <unit id="fallback"><segment><source>fallback.key</source><target>Fallback
            XLIFF);

        self::assertSame(
            ['dashboard.title', 'dashboard.subtitle'],
            array_map(static fn ($item): string => $item->key(), $json->declarations()),
        );
        self::assertSame(
            ['required', 'fallback.key'],
            array_map(static fn ($item): string => $item->key(), $xliff->declarations()),
        );
        self::assertSame(
            ['Required', 'Fallback'],
            array_map(static fn ($item): string => trim($item->message()), $xliff->declarations()),
        );
    }

    public function testPreservesSourceRangeForEscapedJsonKeys(): void
    {
        $declaration = $this->extractor()
            ->extract('file:///workspace/translations/messages.en.json', 'json', '{"first\\u002etitle":"Title"}')
            ->declarations()[0];

        self::assertSame('first.title', $declaration->key());
        self::assertSame(16, $declaration->range()->end()->character() - $declaration->range()->start()->character());
    }

    public function testKeepsDistinctRangesForRepeatedJsonKeys(): void
    {
        $text = '{"first":{"title":"One"},"second":{"title":"Two"}}';
        $declarations = $this->extractor()
            ->extract('file:///workspace/translations/messages.en.json', 'json', $text)
            ->declarations();

        self::assertSame(['first.title', 'second.title'], array_map(static fn ($item): string => $item->key(), $declarations));
        self::assertNotSame($declarations[0]->range()->start()->character(), $declarations[1]->range()->start()->character());
    }

    public function testExtractsJsonXliffAndPhpResources(): void
    {
        $extractor = $this->extractor();
        $json = $extractor->extract('file:///workspace/translations/admin.fr.json', 'json', '{"dashboard":{"title":"Administration"}}');
        $xliff = $extractor->extract('file:///workspace/translations/validators.en.xlf', 'xml', '<xliff><file><body><trans-unit id="1" resname="required"><source>required</source><target>Required</target></trans-unit></body></file></xliff>');
        $php = $extractor->extract('file:///workspace/translations/messages.de.php', 'php', "<?php return ['hello' => 'Hallo'];");

        self::assertSame('dashboard.title', $json->declarations()[0]->key());
        self::assertSame('required', $xliff->declarations()[0]->key());
        self::assertSame('hello', $php->declarations()[0]->key());
    }

    private function extractor(): TranslationExtractor
    {
        return new TranslationExtractor(new PositionConverter(), new UriToPathConverter());
    }
}
