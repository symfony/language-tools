<?php

namespace Symfony\Lsp\Tests\Parser\Php;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;

final class TolerantPhpInheritanceRecoveryTest extends TestCase
{
    /** @param list<string> $names */
    #[DataProvider('incompleteParentProvider')]
    public function testRetainsDeclarationsWhileTheParentNameIsIncomplete(string $source, array $names): void
    {
        $document = (new TolerantPhpParser(new Parser()))->parse($source);

        self::assertSame($names, array_map(static fn ($type): string => $type->name, $document->typeDeclarations));
        self::assertSame(array_fill(0, \count($names), null), array_map(static fn ($type): ?string => $type->parentClassName, $document->typeDeclarations));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function incompleteParentProvider(): iterable
    {
        yield 'parent being typed' => ['<?php class Draft extends ', ['Draft']];
        yield 'later declaration' => ['<?php class Draft extends {} class Complete {}', ['Draft', 'Complete']];
        yield 'parent references and callable' => [<<<'PHP'
            <?php
            class Draft extends {
                public function configure(): void {
                    parent::class;
                    new Registration([parent::class, 'configure']);
                }
            }
            class Complete {}
            PHP, ['Draft', 'Complete']];
    }
}
