<?php

namespace Symfony\Lsp\Tests\Parser\Yaml;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlCommentParser;

final class YamlCommentParserTest extends TestCase
{
    public function testMasksCommentsWithoutMaskingHashesInsideScalars(): void
    {
        $source = <<<'YAML'
            key: value # note
            single: '# kept' # gone
            escaped: 'it''s # kept' # gone
            double: "# kept" # gone
            quoted: "escaped \" # kept" # gone
            plain: it's useful # gone
            url: https://example.test/#fragment
            YAML;

        $comment = str_repeat(' ', \strlen(' # note'));
        self::assertSame(
            'key: value'.$comment."\n".
            "single: '# kept'".$comment."\n".
            "escaped: 'it''s # kept'".$comment."\n".
            'double: "# kept"'.$comment."\n".
            'quoted: "escaped \\" # kept"'.$comment."\n".
            "plain: it's useful".$comment."\n".
            'url: https://example.test/#fragment',
            $this->parser()->mask($source),
        );
    }

    public function testPreservesByteOffsetsAndUtf16PositionsForMultibyteComments(): void
    {
        $source = "key: value # vérifié ✓\nnext: '%env(APP_URL)%'\n";
        $masked = $this->parser()->mask($source);

        self::assertStringEndsWith("\nnext: '%env(APP_URL)%'\n", $masked);
        self::assertSame(\strlen($source), \strlen($masked));
        self::assertSame(
            \strlen(mb_convert_encoding($source, 'UTF-16LE', 'UTF-8')),
            \strlen(mb_convert_encoding($masked, 'UTF-16LE', 'UTF-8')),
        );
    }

    public function testLeavesHashesInsideBlockScalarsUnmasked(): void
    {
        $source = "content: |\n    # kept\n# gone\n";

        self::assertSame("content: |\n    # kept\n      \n", $this->parser()->mask($source));
    }

    public function testRecoversCommentsAfterMalformedYaml(): void
    {
        $source = "broken: !<unterminated\n# recovered\nnext: value\n";

        self::assertSame("broken: !<unterminated\n           \nnext: value\n", $this->parser()->mask($source));
    }

    public function testRecoveryKeepsHashesInsideParsedMultilineScalars(): void
    {
        $source = "quoted: \"first\n  # kept\n  last\"\nbroken: [\n# gone\n";

        self::assertSame("quoted: \"first\n  # kept\n  last\"\nbroken: [\n      \n", $this->parser()->mask($source));
    }

    public function testRecoveryTracksMultilineQuotedScalarsAfterMalformedSyntax(): void
    {
        $source = "broken: !<unterminated\nquoted: \"first\n  # kept\"\n# gone\n";

        self::assertSame("broken: !<unterminated\nquoted: \"first\n  # kept\"\n      \n", $this->parser()->mask($source));
    }

    public function testRecoveryKeepsHashesInsideBlockScalars(): void
    {
        $source = "broken: !<unterminated\ncontent: |\n  # kept\n# gone\n";

        self::assertSame("broken: !<unterminated\ncontent: |\n  # kept\n      \n", $this->parser()->mask($source));
    }

    private function parser(): YamlCommentParser
    {
        return new YamlCommentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()));
    }
}
