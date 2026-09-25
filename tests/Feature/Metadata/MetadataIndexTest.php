<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Metadata\FormType;
use Symfony\Lsp\Feature\Metadata\MetadataIndex;
use Symfony\Lsp\Feature\Metadata\ValidationConstraint;

final class MetadataIndexTest extends TestCase
{
    public function testLooksClassesUpRegardlessOfCaseAndLeadingBackslash(): void
    {
        $index = new MetadataIndex();
        $formType = new FormType('App\\Form\\ArticleType', 'article', ['data_class'], []);
        $constraint = new ValidationConstraint('NotBlank', 'Symfony\\Component\\Validator\\Constraints\\NotBlank', ['message']);
        $index->replace([$formType], [$constraint], true, true);

        self::assertSame($formType, $index->formType('\\app\\form\\ARTICLETYPE'));
        self::assertSame($constraint, $index->constraint('\\symfony\\component\\validator\\constraints\\notblank'));
        self::assertSame($constraint, $index->constraint('NotBlank'));
    }

    public function testKeepsFormTypesSortedByClassName(): void
    {
        $index = new MetadataIndex();
        $index->replace([
            new FormType('App\\Form\\second', null, [], []),
            new FormType('App\\Form\\First', null, [], []),
        ], [], true, true);

        self::assertSame(['App\\Form\\First', 'App\\Form\\second'], array_column($index->formTypes(), 'className'));
    }
}
