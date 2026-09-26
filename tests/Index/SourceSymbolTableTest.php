<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Index\ClassNameKey;
use Symfony\Lsp\Index\NamedSourceSymbolInterface;
use Symfony\Lsp\Index\SourceSymbolTable;

final class SourceSymbolTableTest extends TestCase
{
    public function testIndexesSymbolsByKindAndName(): void
    {
        /** @var SourceSymbolTable<TableSourceSymbol> $table */
        $table = new SourceSymbolTable();
        $table->add('role', $admin = new TableSourceSymbol('ROLE_ADMIN', true));
        $table->add('role', $adminUsage = new TableSourceSymbol('ROLE_ADMIN', false));
        $table->add('firewall', $main = new TableSourceSymbol('main', true));

        self::assertSame([$admin, $adminUsage], $table->symbols('role'));
        self::assertSame([$admin, $adminUsage], $table->symbols('role', 'ROLE_ADMIN'));
        self::assertSame([], $table->symbols('role', 'ROLE_USER'));
        self::assertSame([$main], $table->symbols('firewall'));
        self::assertSame([], $table->symbols('provider'));
    }

    public function testSortsNamesAndSeparatesDeclarations(): void
    {
        /** @var SourceSymbolTable<TableSourceSymbol> $table */
        $table = new SourceSymbolTable();
        $table->add('role', new TableSourceSymbol('ROLE_USER', false));
        $table->add('role', new TableSourceSymbol('ROLE_ADMIN', true));
        $table->add('role', new TableSourceSymbol('ROLE_ADMIN', false));

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $table->names('role'));
        self::assertSame(['ROLE_ADMIN'], $table->declarationNames('role'));
        self::assertSame([], $table->names('firewall'));
        self::assertSame([], $table->declarationNames('firewall'));
    }

    public function testKeepsNumericNamesAsStrings(): void
    {
        /** @var SourceSymbolTable<TableSourceSymbol> $table */
        $table = new SourceSymbolTable();
        $table->add('group', $symbol = new TableSourceSymbol('1', true));

        self::assertSame(['1'], $table->names('group'));
        self::assertSame(['1'], $table->declarationNames('group'));
        self::assertSame([$symbol], $table->symbols('group', '1'));
    }

    public function testMatchesNamesThroughTheKeyOfTheirKindAndOtherKindsExactly(): void
    {
        /** @var SourceSymbolTable<TableSourceSymbol> $table */
        $table = new SourceSymbolTable(['class' => ClassNameKey::from(...)]);
        $table->add('class', $declaration = new TableSourceSymbol('App\\Product', true));
        $table->add('class', $reference = new TableSourceSymbol('\\app\\PRODUCT', false));
        $table->add('event', $event = new TableSourceSymbol('App\\Product', false));

        self::assertSame([$declaration, $reference], $table->symbols('class', 'app\\product'));
        self::assertSame(['App\\Product'], $table->names('class'));
        self::assertSame([$event], $table->symbols('event', 'App\\Product'));
        self::assertSame([], $table->symbols('event', 'app\\product'));
    }

    public function testNamesReflectSymbolsAddedAfterAName(): void
    {
        /** @var SourceSymbolTable<TableSourceSymbol> $table */
        $table = new SourceSymbolTable();
        $table->add('role', new TableSourceSymbol('ROLE_USER', false));

        self::assertSame([], $table->declarationNames('role'));

        $table->add('role', new TableSourceSymbol('ROLE_USER', true));

        self::assertSame(['ROLE_USER'], $table->declarationNames('role'));
    }
}

final class TableSourceSymbol implements NamedSourceSymbolInterface
{
    public function __construct(
        public readonly string $name,
        public readonly bool $declaration,
    ) {
    }
}
