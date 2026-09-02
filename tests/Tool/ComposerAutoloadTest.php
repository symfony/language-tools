<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

final class ComposerAutoloadTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    public function testStrictPsrValidationHasAComposerScript(): void
    {
        $composer = self::composerConfiguration();
        self::assertIsArray($composer['scripts']);
        self::assertSame('@composer dump-autoload --optimize --strict-psr', $composer['scripts']['autoload-check'] ?? null);
    }

    public function testClassmapExclusionsRemainLimitedToNonRootTrees(): void
    {
        $composer = self::composerConfiguration();
        self::assertIsArray($composer['autoload-dev']);
        self::assertIsArray($composer['autoload-dev']['exclude-from-classmap']);

        $exclusions = $composer['autoload-dev']['exclude-from-classmap'];
        self::assertSame([
            '/tests/Fixtures/RuntimeApplication/',
            '/tools/vscode-guide-scenarios/',
        ], $exclusions);

        foreach ($exclusions as $exclusion) {
            foreach ((new Finder())->files()->in(self::ROOT.$exclusion)->exclude(['var', 'vendor'])->name('*.php') as $file) {
                if (1 !== preg_match('/^namespace ([^;]+);/m', $file->getContents(), $matches)) {
                    continue;
                }

                self::assertFalse(str_starts_with($matches[1], 'Symfony\\Lsp\\'), \sprintf('%s%s declares a root project namespace inside an excluded classmap tree.', $exclusion, $file->getRelativePathname()));
            }
        }
    }

    public function testToolClassesResolveToTheirCaseSensitivePaths(): void
    {
        $composer = self::composerConfiguration();
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

    /** @return array<array-key, mixed> */
    private static function composerConfiguration(): array
    {
        $composer = json_decode((string) file_get_contents(self::ROOT.'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);

        return $composer;
    }
}
