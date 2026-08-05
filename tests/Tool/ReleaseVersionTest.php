<?php

namespace Symfony\Lsp\Tests\Tool;

require_once \dirname(__DIR__, 2).'/tools/ReleaseVersion.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tools\ReleaseVersion;

final class ReleaseVersionTest extends TestCase
{
    #[DataProvider('validVersionProvider')]
    public function testAcceptsReleaseVersions(string $version): void
    {
        $releaseVersion = new ReleaseVersion($version);

        self::assertSame($version, $releaseVersion->value());
        self::assertSame('v'.$version, $releaseVersion->tag());
    }

    /** @return iterable<string, array{string}> */
    public static function validVersionProvider(): iterable
    {
        yield 'stable' => ['1.0.0'];
        yield 'release candidate' => ['1.0.0-rc.1'];
        yield 'dotted prerelease' => ['1.0.0-beta.2'];
    }

    #[DataProvider('invalidVersionProvider')]
    public function testRejectsInvalidReleaseVersions(string $version): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The release version must use X.Y.Z or X.Y.Z-PRERELEASE format.');

        new ReleaseVersion($version);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidVersionProvider(): iterable
    {
        yield 'tag prefix' => ['v1.0.0'];
        yield 'missing patch' => ['1.0'];
        yield 'leading zero' => ['01.0.0'];
        yield 'empty prerelease' => ['1.0.0-'];
        yield 'leading prerelease zero' => ['1.0.0-01'];
        yield 'build metadata' => ['1.0.0+build'];
    }

    public function testComparesReleaseVersionsUsingSemanticVersionPrecedence(): void
    {
        self::assertTrue((new ReleaseVersion('1.0.0-preview.2'))->isGreaterThan('1.0.0-preview.1'));
        self::assertTrue((new ReleaseVersion('1.0.0-rc.2'))->isGreaterThan('1.0.0-rc.1'));
        self::assertTrue((new ReleaseVersion('1.0.0'))->isGreaterThan('1.0.0-preview.2'));
        self::assertFalse((new ReleaseVersion('1.0.0-preview.2'))->isGreaterThan('1.0.0'));
    }
}
