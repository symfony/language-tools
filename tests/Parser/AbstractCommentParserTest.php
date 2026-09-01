<?php

namespace Symfony\Lsp\Tests\Parser;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\AbstractCommentParser;
use Symfony\Lsp\Parser\CommentParseResult;

final class AbstractCommentParserTest extends TestCase
{
    public function testCachesTheLastParsedSource(): void
    {
        $parser = new class extends AbstractCommentParser {
            public int $calls = 0;

            protected function parseSource(string $source): CommentParseResult
            {
                ++$this->calls;

                return new CommentParseResult(strtoupper($source), []);
            }
        };

        self::assertSame('FIRST', $parser->mask('first'));
        self::assertSame([], $parser->comments('first'));
        self::assertSame('FIRST', $parser->parse('first')->masked);
        self::assertSame(1, $parser->calls);
        self::assertSame('SECOND', $parser->mask('second'));
        self::assertSame(2, $parser->calls);
    }

    public function testMasksAsciiWhilePreservingNewlinesAndMultibyteBytes(): void
    {
        $parser = new class extends AbstractCommentParser {
            protected function parseSource(string $source): CommentParseResult
            {
                $masked = $source;
                $this->maskRange($masked, $source, 0, \strlen($source));

                return new CommentParseResult($masked, []);
            }
        };
        $source = "aé\nb✓";
        $masked = $parser->mask($source);

        self::assertSame(" é\n ✓", $masked);
        self::assertSame(\strlen($source), \strlen($masked));
        self::assertSame(
            \strlen(mb_convert_encoding($source, 'UTF-16LE', 'UTF-8')),
            \strlen(mb_convert_encoding($masked, 'UTF-16LE', 'UTF-8')),
        );
    }
}
