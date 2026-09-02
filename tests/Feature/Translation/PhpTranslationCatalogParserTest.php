<?php

namespace Symfony\Lsp\Tests\Feature\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Translation\PhpTranslationCatalogParser;

final class PhpTranslationCatalogParserTest extends TestCase
{
    #[DataProvider('nestedReturnProvider')]
    public function testIgnoresReturnsInsideNestedScopes(string $helper): void
    {
        $entries = (new PhpTranslationCatalogParser())->parse(<<<PHP
            <?php

            {$helper}

            return ['catalog.key' => 'Catalog message'];
            PHP);

        self::assertSame(['catalog.key'], array_column($entries, 'key'));
    }

    /** @return iterable<string, array{string}> */
    public static function nestedReturnProvider(): iterable
    {
        yield 'closure' => [<<<'PHP'
            $helper = static function (): array {
                return ['closure.key' => 'Closure message'];
            };
            PHP];
        yield 'function' => [<<<'PHP'
            function helper(): array
            {
                return ['function.key' => 'Function message'];
            }
            PHP];
        yield 'function with an interpolated string' => [<<<'PHP'
            function helper(string $name): array
            {
                $message = "${name}";

                return ['function.key' => $message];
            }
            PHP];
    }
}
