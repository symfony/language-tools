<?php

namespace Symfony\Lsp\Tests\Feature\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Translation\TranslationDeclaration;
use Symfony\Lsp\Feature\Translation\TranslationIndex;
use Symfony\Lsp\Feature\Translation\TranslationMessage;
use Symfony\Lsp\Feature\Translation\TranslationReference;
use Symfony\Lsp\Feature\Translation\TranslationSourceFacts;

final class TranslationIndexTest extends TestCase
{
    public function testIndexesRuntimeAndSourceFacts(): void
    {
        $index = new TranslationIndex();
        $index->replaceRuntime(
            true,
            new TranslationMessage('article.title', 'messages', 'en', 'Article'),
            new TranslationMessage('article.title', 'messages', 'fr', 'Article'),
            new TranslationMessage('admin.title', 'admin', 'en', 'Admin'),
        );
        $declaration = new TranslationDeclaration('article.body', 'messages', 'en', 'Body', 'file:///translations/messages.en.yaml', $this->range());
        $reference = new TranslationReference('article.title', 'messages', 'file:///src/Controller.php', $this->range());
        $index->replaceSources(new TranslationSourceFacts('file:///source', [$declaration], [$reference], ['%current_domain_name%']));

        self::assertCount(2, $index->messages('messages', 'article.title'));
        self::assertSame([$declaration], $index->declarations('messages', 'article.body'));
        self::assertSame([$reference], $index->references('messages', 'article.title'));
        self::assertSame(['article.body', 'article.title'], $index->keys('messages', 'article.'));
        self::assertSame(['admin', 'messages'], $index->domains());
        self::assertSame(['en', 'fr'], $index->locales());
        self::assertSame(['current_domain_name'], $index->globalParameters());
        self::assertTrue($index->isComplete());
    }

    public function testInvalidatesIndexesAfterSourceAndRuntimeChanges(): void
    {
        $index = new TranslationIndex();
        $first = new TranslationDeclaration('first', 'messages', 'en', 'First', 'file:///translations/messages.en.yaml', $this->range());
        $second = new TranslationDeclaration('second', 'messages', 'fr', 'Second', 'file:///translations/messages.fr.yaml', $this->range());
        $index->replaceSources(new TranslationSourceFacts('file:///source', [$first]));
        self::assertSame(['first'], $index->keys('messages', ''));

        $index->overlay(new TranslationSourceFacts('file:///source', [$second], [], [], true));
        self::assertSame(['second'], $index->keys('messages', ''));
        self::assertSame([], $index->declarations('messages', 'first'));
        self::assertNull($index->globalParameters());

        $index->replaceRuntime(false, new TranslationMessage('runtime', 'runtime', 'de', 'Runtime'));
        self::assertSame(['messages', 'runtime'], $index->domains());
        self::assertSame(['de', 'fr'], $index->locales());
        self::assertFalse($index->isComplete());
    }

    private function range(): Range
    {
        return new Range(new Position(0, 0), new Position(0, 1));
    }
}
