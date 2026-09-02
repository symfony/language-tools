<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\ReleaseReference;

final class ReleaseReferenceTest extends TestCase
{
    #[DataProvider('referenceProvider')]
    public function testResolvesEmbeddedVersions(string $type, string $name, string $embeddedVersion): void
    {
        $reference = new ReleaseReference($type, $name);

        self::assertSame($type, $reference->type);
        self::assertSame($name, $reference->name);
        self::assertSame($embeddedVersion, $reference->embeddedVersion);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function referenceProvider(): iterable
    {
        yield 'stable tag' => ['tag', 'v1.2.3', '1.2.3'];
        yield 'prerelease tag' => ['tag', 'v1.2.3-rc.1', '1.2.3-rc.1'];
        yield 'development branch' => ['branch', 'main', 'dev'];
    }

    #[DataProvider('invalidReferenceProvider')]
    public function testRejectsInvalidReferences(string $type, string $name, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ReleaseReference($type, $name);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidReferenceProvider(): iterable
    {
        yield 'unknown type' => ['commit', 'main', 'The release reference type must be "branch" or "tag".'];
        yield 'empty name' => ['branch', '', 'The release reference name must be a non-empty path component.'];
        yield 'nested name' => ['branch', 'feature/test', 'The release reference name must be a non-empty path component.'];
        yield 'tag without prefix' => ['tag', '1.2.3', 'A release tag must use the vX.Y.Z or vX.Y.Z-PRERELEASE format.'];
        yield 'invalid version' => ['tag', 'v1.2', 'A release tag must use the vX.Y.Z or vX.Y.Z-PRERELEASE format.'];
    }
}
