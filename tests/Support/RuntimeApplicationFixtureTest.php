<?php

namespace Symfony\Lsp\Tests\Support;

use PHPUnit\Framework\TestCase;

final class RuntimeApplicationFixtureTest extends TestCase
{
    public function testCleansOnlyItsUniqueCacheNamespace(): void
    {
        $first = new RuntimeApplicationFixture();
        $second = new RuntimeApplicationFixture();
        mkdir($first->cachePath, 0777, true);
        mkdir($second->cachePath, 0777, true);

        try {
            self::assertNotSame($first->cachePath, $second->cachePath);

            $first->cleanup();

            self::assertDirectoryDoesNotExist($first->cachePath);
            self::assertDirectoryExists($second->cachePath);
        } finally {
            $first->cleanup();
            $second->cleanup();
        }
    }
}
