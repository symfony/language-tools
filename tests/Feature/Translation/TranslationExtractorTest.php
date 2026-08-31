<?php

namespace Symfony\Lsp\Tests\Feature\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Translation\TranslationExtractor;
use Symfony\Lsp\Feature\Translation\TranslationPlaceholders;

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

        self::assertSame(['article.title'], array_map(static fn ($item): string => $item->key, $facts->declarations));
        self::assertSame(['name'], $facts->declarations[0]->placeholders());
        self::assertSame([
            ['article.title', 'messages', ['name']],
            ['admin.title', 'admin', []],
        ], array_map(static fn ($item): array => [$item->key, $item->domain, $item->placeholders], $references->references));
    }

    public function testExtractsNamedPhpTranslationKeys(): void
    {
        $references = $this->extractor()->extract('file:///workspace/src/Controller.php', 'php', <<<'PHP'
            <?php
            $translator->trans(id: 'panel.title', domain: 'admin');
            t(message: 'article.title');
            new TranslatableMessage(message: 'article.title');
            $translator->trans(id: $key, domain: 'admin');
            PHP)->references;

        self::assertSame([
            ['panel.title', 'admin'],
            ['article.title', 'messages'],
            ['article.title', 'messages'],
        ], array_map(static fn ($item): array => [$item->key, $item->domain], $references));
    }

    public function testExtractsBareTwigHashKeysAsPlaceholders(): void
    {
        $references = $this->extractor()->extract('file:///workspace/templates/edit.html.twig', 'twig', <<<'TWIG'
            <h1>{{ 'title.edit_post'|trans({id: post.id, '%name%': post.name}) }}</h1>
            TWIG);

        self::assertSame(
            [['title.edit_post', ['id', 'name']]],
            array_map(static fn ($item): array => [$item->key, $item->placeholders], $references->references),
        );
    }

    public function testExtractsNestedTwigParameterMapsAndIgnoresAbsentMaps(): void
    {
        $references = $this->extractor()->extract('file:///workspace/templates/schedule.html.twig', 'twig', <<<'TWIG'
            {{ 'schedule.cfp_still_open'|trans({
                '%cfp_url%': path('conference_cfp', { slug: conference.slug }),
                '%cfp_end_date%': conference.cfpEndsAt|date,
                '%num_days_left%': 3
            }) }}
            {{ 'registration.ticket_sales_countdown'|trans }}
            TWIG)->references;

        self::assertSame(['cfp_end_date', 'cfp_url', 'num_days_left'], $references[0]->placeholders);
        self::assertNull($references[1]->placeholders);
    }

    public function testTreatsBracesAsPlaceholdersOnlyInIcuCatalogs(): void
    {
        $extractor = $this->extractor();
        $icu = $extractor->extract('file:///workspace/translations/messages+intl-icu.en.xlf', 'xml', <<<'XLF'
            <xliff><file><body>
                <trans-unit id="title.edit_post">
                    <source>title.edit_post</source>
                    <target>Edit post #{id, number}</target>
                </trans-unit>
            </body></file></xliff>
            XLF);
        $plain = $extractor->extract('file:///workspace/translations/messages.en.yaml', 'yaml', "joomla_syntax: 'Joomla uses curly braces {mautic} in templates'\n");

        self::assertSame('messages', $icu->declarations[0]->domain);
        self::assertTrue($icu->declarations[0]->icu);
        self::assertSame(['id'], $icu->declarations[0]->placeholders());
        self::assertFalse($plain->declarations[0]->icu);
        self::assertSame([], $plain->declarations[0]->placeholders());
    }

    public function testExtractsIcuArgumentsWithoutTreatingSelectMessagesAsPlaceholders(): void
    {
        self::assertSame(
            ['conference_name', 'count', 'num_hours', 'num_rooms', 'person_name', 'trainer_name', 'type'],
            TranslationPlaceholders::extract(<<<'ICU'
                {num_rooms, select,
                    1 {Room}
                    other {Rooms for {conference_name}}
                }
                {num_hours, select,
                    1 {{num_hours} hour}
                    other {{num_hours} hours}
                }
                {type, select,
                    online {We, Symfony, certify {trainer_name}.}
                    other {Speaker {trainer_name}.}
                }
                You're {person_name}. It's {count, plural, one {# item} other {# items}}.
                ICU, true),
        );
    }

    public function testExtractsIniDeclarationsWithDirectoryLocales(): void
    {
        $facts = $this->extractor()->extract('file:///workspace/app/bundles/ApiBundle/Translations/en_US/messages.ini', 'ini', <<<'INI'
            mautic.api.auth.error.accessdenied="API authorization denied."
            mautic.api.auth.error.granted="Access granted to %name%."
            INI);

        self::assertSame(
            [
                ['mautic.api.auth.error.accessdenied', 'messages', 'en_US', 'API authorization denied.', []],
                ['mautic.api.auth.error.granted', 'messages', 'en_US', 'Access granted to %name%.', ['name']],
            ],
            array_map(
                static fn ($item): array => [$item->key, $item->domain, $item->locale, $item->message, $item->placeholders()],
                $facts->declarations,
            ),
        );
    }

    public function testPreservesHyphenatedYamlKeys(): void
    {
        $facts = $this->extractor()->extract('file:///workspace/translations/messages.en.yaml', 'yaml', "article-title: Article\n");

        self::assertSame('article-title', $facts->declarations[0]->key);
    }

    public function testIgnoresTwigReferencesInsideDocumentationComments(): void
    {
        $references = $this->extractor()->extract('file:///workspace/templates/page.html.twig', 'twig', <<<'TWIG'
            {## Use t('documented.translation') in examples. #}
            {{ t('page.title') }}
            TWIG)->references;

        self::assertSame(['page.title'], array_map(static fn ($item): string => $item->key, $references));
    }

    public function testExtractsTwigFiltersSeparatedByComments(): void
    {
        $references = $this->extractor()->extract('file:///workspace/templates/page.html.twig', 'twig', <<<'TWIG'
            {{ 'page.title'
                # keep the key literal
                | trans }}
            TWIG)->references;

        self::assertSame(['page.title'], array_map(static fn ($reference): string => $reference->key, $references));
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
            array_map(static fn ($item): string => $item->key, $json->declarations),
        );
        self::assertSame(
            ['required', 'fallback.key'],
            array_map(static fn ($item): string => $item->key, $xliff->declarations),
        );
        self::assertSame(
            ['Required', 'Fallback'],
            array_map(static fn ($item): string => trim($item->message), $xliff->declarations),
        );
    }

    public function testPreservesSourceRangeForEscapedJsonKeys(): void
    {
        $declaration = $this->extractor()
            ->extract('file:///workspace/translations/messages.en.json', 'json', '{"first\\u002etitle":"Title"}')
            ->declarations[0];

        self::assertSame('first.title', $declaration->key);
        self::assertSame(16, $declaration->range->end->character - $declaration->range->start->character);
    }

    public function testKeepsDistinctRangesForRepeatedJsonKeys(): void
    {
        $text = '{"first":{"title":"One"},"second":{"title":"Two"}}';
        $declarations = $this->extractor()
            ->extract('file:///workspace/translations/messages.en.json', 'json', $text)
            ->declarations;

        self::assertSame(['first.title', 'second.title'], array_map(static fn ($item): string => $item->key, $declarations));
        self::assertNotSame($declarations[0]->range->start->character, $declarations[1]->range->start->character);
    }

    public function testExtractsJsonXliffAndPhpResources(): void
    {
        $extractor = $this->extractor();
        $json = $extractor->extract('file:///workspace/translations/admin.fr.json', 'json', '{"dashboard":{"title":"Administration"}}');
        $xliff = $extractor->extract('file:///workspace/translations/validators.en.xlf', 'xml', '<xliff><file><body><trans-unit id="1" resname="required"><source>required</source><target>Required</target></trans-unit></body></file></xliff>');
        $php = $extractor->extract('file:///workspace/translations/messages.de.php', 'php', "<?php return ['hello' => 'Hallo'];");

        self::assertSame('dashboard.title', $json->declarations[0]->key);
        self::assertSame('required', $xliff->declarations[0]->key);
        self::assertSame('hello', $php->declarations[0]->key);
    }

    public function testDecodesTwigTranslationKeysLikeTheVendoredLexer(): void
    {
        $literals = ['"\\x66oo"', '"\\146oo"', '"line\\nkey"'];
        $text = implode("\n", array_map(static fn (string $literal): string => '{{ '.$literal.'|trans }}', $literals));
        $references = $this->extractor()->extract('file:///workspace/templates/page.html.twig', 'twig', $text)->references;

        self::assertSame(
            array_map($this->lexedTwigString(...), $literals),
            array_map(static fn ($reference): string => $reference->key, $references),
        );
    }

    public function testExtractsPhpReferencesFromParserFacts(): void
    {
        $references = $this->extractor()->extract('file:///workspace/src/Controller.php', 'php', <<<'PHP'
            <?php
            use Symfony\Component\Translation\TranslatableMessage as Message;

            $example = '$translator->trans("ignored.key")';
            $translator->trans('article.title', ['%name%' => $name]);
            new Message('panel.title', ['%id%' => 1], 'admin');
            PHP)->references;

        self::assertSame(
            [
                ['article.title', 'messages', ['name']],
                ['panel.title', 'admin', ['id']],
            ],
            array_map(static fn ($reference): array => [$reference->key, $reference->domain, $reference->placeholders], $references),
        );
    }

    public function testPreservesTwigDefaultDomainsAndTranslationTags(): void
    {
        $references = $this->extractor()->extract('file:///workspace/templates/page.html.twig', 'twig', <<<'TWIG'
            {% trans_default_domain 'admin' %}
            {{ 'filter.key'|trans }}
            {{ t('function.key') }}
            {% trans from 'tags' %} tag.key {% endtrans %}
            TWIG)->references;

        self::assertSame(
            [
                ['filter.key', 'admin'],
                ['function.key', 'admin'],
                ['tag.key', 'tags'],
            ],
            array_map(static fn ($reference): array => [$reference->key, $reference->domain], $references),
        );
    }

    private function lexedTwigString(string $literal): string
    {
        $script = <<<'PHP'
            require $argv[1];
            $environment = new Twig\Environment(new Twig\Loader\ArrayLoader());
            $stream = (new Twig\Lexer($environment))->tokenize(
                new Twig\Source('{{ '.$argv[2].' }}', 'translation-key'),
            );
            $stream->next();
            echo json_encode($stream->next()->getValue(), JSON_THROW_ON_ERROR);
            PHP;
        exec(\sprintf(
            '%s -r %s %s %s 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg(__DIR__.'/../../Fixtures/RuntimeApplication/vendor/autoload.php'),
            escapeshellarg($literal),
        ), $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        $value = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsString($value);

        return $value;
    }

    private function extractor(): TranslationExtractor
    {
        $converter = new PositionConverter();

        return TranslationExtractorTestFactory::create($converter);
    }

    public function testMeasuresTwigRangesCorrectlyAfterMultibyteComments(): void
    {
        $text = "{# vérifié #} {{ trans('greeting') }}";
        $facts = $this->extractor()->extract('file:///workspace/templates/page.html.twig', 'twig', $text);

        self::assertCount(1, $facts->references);
        $start = $facts->references[0]->range->start;
        self::assertSame(
            (new PositionConverter())->toPosition($text, (int) strpos($text, 'greeting'))->character,
            $start->character,
        );
    }

    public function testIgnoresTranslationCallsInPhpComments(): void
    {
        $facts = $this->extractor()->extract('file:///workspace/src/Controller.php', 'php', <<<'PHP'
            <?php
            // $translator->trans('commented.key');
            /* $translator->trans('blocked.key'); */
            $translator->trans('live.key');
            PHP);

        self::assertSame(['live.key'], array_map(static fn ($item): string => $item->key, $facts->references));
    }
}
