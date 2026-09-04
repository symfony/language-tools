<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Index\SourceIndexJsonLinesCodec;
use Symfony\Lsp\Index\SourceIndexStoreInterface;

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 */
final class SourceIndexJsonLinesCodecTest extends TestCase
{
    public function testEncodesTheExistingJsonLinesFormat(): void
    {
        $codec = new SourceIndexJsonLinesCodec('test');

        self::assertSame("{\"schemaVersion\":9,\"serverVersion\":\"test\"}\n", $codec->encodeHeader());
        self::assertSame(
            '{"path":"src/A.php","size":1,"modifiedAt":1700000001,"hash":"'.str_repeat('a', 64)."\",\"languageId\":\"php\",\"runtimeStructure\":null,\"providers\":{\"routes\":\"payload\"}}\n",
            $codec->encodeRecord('src/A.php', $this->metadata(), ['routes' => 'payload']),
        );
        self::assertSame("{\"path\":\"src/A.php\",\"deleted\":true}\n", $codec->encodeDeletion('src/A.php'));
    }

    public function testValidatesHeaders(): void
    {
        $codec = new SourceIndexJsonLinesCodec('test');

        self::assertTrue($codec->validHeader($codec->encodeHeader()));
        self::assertFalse($codec->validHeader("{\"schemaVersion\":7,\"serverVersion\":\"test\"}\n"));
        self::assertFalse($codec->validHeader("{\"schemaVersion\":9,\"serverVersion\":\"other\"}\n"));
    }

    public function testDefersProviderPayloadValidationUntilPayloadDecoding(): void
    {
        $line = json_encode(
            ['path' => 'src/A.php', ...$this->metadata(), 'providers' => ['routes' => 42]],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        )."\n";
        $codec = new SourceIndexJsonLinesCodec('test');

        self::assertSame(
            ['path' => 'src/A.php', 'metadata' => $this->metadata()],
            $codec->decodeMetadata($line),
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('A source index provider payload is invalid.');
        $codec->decodeRecord($line);
    }

    public function testRejectsTornRecordsWithoutDecodingPayloads(): void
    {
        $codec = new SourceIndexJsonLinesCodec('test');

        self::assertNull($codec->decodeMetadata('{"path":"src/A.php"'));
        self::assertNull($codec->decodeRecord('{"path":"src/A.php"'));
    }

    /** @return SourceIndexMetadata */
    private function metadata(): array
    {
        return [
            'size' => 1,
            'modifiedAt' => 1_700_000_001,
            'hash' => str_repeat('a', 64),
            'languageId' => 'php',
            'runtimeStructure' => null,
        ];
    }
}
