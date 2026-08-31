<?php

namespace Symfony\Lsp\Tests\Parser\Php;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\CommentParseResult;
use Symfony\Lsp\Parser\Php\LastResultPhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;

final class PhpCommentParserTest extends TestCase
{
    private PhpCommentParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhpCommentParser();
    }

    public function testMasksLineComments(): void
    {
        self::assertSame(
            "<?php\n\$a = 1;         \n\$b = 2;       \n",
            $this->parser->mask("<?php\n\$a = 1; // route\n\$b = 2; # hash\n"),
        );
    }

    public function testMasksBlockAndDocCommentsPreservingNewlines(): void
    {
        self::assertSame(
            "<?php\n   \n        \n   \n\$a = 1;\n        \$b = 2;\n",
            $this->parser->mask("<?php\n/**\n * route\n */\n\$a = 1;\n/* x */ \$b = 2;\n"),
        );
    }

    public function testLeavesAttributesIntact(): void
    {
        $source = "<?php\n#[Route('/checkout')]\nfunction a(): void {}\n";

        self::assertSame($source, $this->parser->mask($source));
    }

    public function testLeavesStringsAndHeredocIntact(): void
    {
        $source = "<?php\n\$a = '// not a comment';\n\$b = <<<TXT\n/* still text */\nTXT;\n";

        self::assertSame($source, $this->parser->mask($source));
    }

    public function testPreservesByteOffsetsAndUtf16PositionsForMultibyteComments(): void
    {
        $source = "<?php /* café ✓ */ \$a = 1;\n";
        $masked = $this->parser->mask($source);

        self::assertSame("<?php       é ✓    \$a = 1;\n", $masked);
        self::assertSame(\strlen($source), \strlen($masked));
        self::assertSame(mb_strlen($source, 'UTF-8'), mb_strlen($masked, 'UTF-8'));
        self::assertSame(
            \strlen(mb_convert_encoding($source, 'UTF-16LE', 'UTF-8')),
            \strlen(mb_convert_encoding($masked, 'UTF-16LE', 'UTF-8')),
        );
    }

    public function testMasksUnterminatedBlockCommentsToTheEnd(): void
    {
        self::assertSame(
            "<?php\n       \n       \n",
            $this->parser->mask("<?php\n/* open\n\$x = 1;\n"),
        );
    }

    public function testLeavesCommentsSwallowedByUnterminatedStringsUnmasked(): void
    {
        $source = "<?php \$a = 'open; // trailing\n";

        self::assertSame($source, $this->parser->mask($source));
    }

    public function testCachesTheLastResult(): void
    {
        $inner = new class implements PhpCommentParserInterface {
            public int $calls = 0;

            public function parse(string $source): CommentParseResult
            {
                ++$this->calls;

                return new CommentParseResult(strtoupper($source), []);
            }

            public function mask(string $source): string
            {
                return $this->parse($source)->masked;
            }

            public function comments(string $source): array
            {
                return $this->parse($source)->comments;
            }
        };
        $parser = new LastResultPhpCommentParser($inner);

        self::assertSame('<?PHP A;', $parser->mask('<?php a;'));
        self::assertSame([], $parser->comments('<?php a;'));
        self::assertSame(1, $inner->calls);
        self::assertSame('<?PHP B;', $parser->mask('<?php b;'));
        self::assertSame(2, $inner->calls);
    }
}
