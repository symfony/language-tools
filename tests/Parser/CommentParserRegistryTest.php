<?php

namespace Symfony\Lsp\Tests\Parser;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\CommentParserRegistry;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;

final class CommentParserRegistryTest extends TestCase
{
    public function testDispatchesByLanguageAndPreservesUnknownSources(): void
    {
        $registry = new CommentParserRegistry([
            'php' => new PhpCommentParser(),
            'twig' => new TwigCommentParser(),
        ]);

        self::assertSame('<?php        ', $registry->mask('php', '<?php // note'));
        self::assertSame('          ', $registry->mask('twig', '{# note #}'));
        self::assertSame([' note'], array_column($registry->comments('php', '<?php // note'), 'content'));
        self::assertSame([' note '], array_column($registry->comments('twig', '{# note #}'), 'content'));
        self::assertSame('unchanged', $registry->mask('yaml', 'unchanged'));
        self::assertSame([], $registry->comments('yaml', 'unchanged'));
    }
}
