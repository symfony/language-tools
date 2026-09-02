<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

final class ComposerAutoloadTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    public function testToolClassesResolveToTheirCaseSensitivePaths(): void
    {
        $composer = json_decode((string) file_get_contents(self::ROOT.'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['autoload-dev']);
        self::assertIsArray($composer['autoload-dev']['psr-4']);

        $mappings = $composer['autoload-dev']['psr-4'];
        uksort($mappings, static fn (string $first, string $second): int => \strlen($second) <=> \strlen($first));

        foreach ((new Finder())->files()->in(self::ROOT.'/tools')->name('*.php') as $file) {
            if (1 !== preg_match('/^namespace ([^;]+);/m', $file->getContents(), $matches)) {
                continue;
            }
            $class = $matches[1].'\\'.$file->getBasename('.php');
            if (!str_starts_with($class, 'Symfony\\Lsp\\Tools\\')) {
                continue;
            }
            $resolved = null;

            foreach ($mappings as $prefix => $directory) {
                self::assertIsString($directory);
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }

                $resolved = rtrim($directory, '/').'/'.str_replace('\\', '/', substr($class, \strlen($prefix))).'.php';
                break;
            }

            self::assertSame('tools/'.$file->getRelativePathname(), $resolved, $class);
        }
    }
}
