<?php

namespace Symfony\Lsp\Tests\Parser\Yaml;

use PHPUnit\Framework\TestCase;
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
            (new YamlCommentParser())->mask($source),
        );
    }

    public function testPreservesByteOffsetsAndUtf16PositionsForMultibyteComments(): void
    {
        $source = "key: value # vérifié ✓\nnext: '%env(APP_URL)%'\n";
        $masked = (new YamlCommentParser())->mask($source);

        self::assertStringEndsWith("\nnext: '%env(APP_URL)%'\n", $masked);
        self::assertSame(\strlen($source), \strlen($masked));
        self::assertSame(
            \strlen(mb_convert_encoding($source, 'UTF-16LE', 'UTF-8')),
            \strlen(mb_convert_encoding($masked, 'UTF-16LE', 'UTF-8')),
        );
    }
}
