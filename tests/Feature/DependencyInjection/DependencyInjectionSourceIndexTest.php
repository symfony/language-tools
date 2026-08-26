<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionReference;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSymbolKind;
use Symfony\Lsp\Feature\DependencyInjection\ParameterDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\ServiceDeclaration;

final class DependencyInjectionSourceIndexTest extends TestCase
{
    public function testIndexesDeclarationsReferencesAndClassRelationships(): void
    {
        $range = $this->range();
        $service = new ServiceDeclaration('app.service', 'file:///services.yaml', $range, 'App\\Service', decorates: 'app.inner');
        $parameter = new ParameterDeclaration('app.parameter', 'file:///services.yaml', $range);
        $reference = new DependencyInjectionReference(DependencyInjectionSymbolKind::Service, 'app.service', 'file:///Controller.php', $range);
        $parent = new PhpClassDeclaration('App\\ParentService', 'file:///ParentService.php', $range);
        $child = new PhpClassDeclaration('App\\ChildService', 'file:///ChildService.php', $range, 'App\\ParentService');
        $index = new DependencyInjectionSourceIndex();
        $index->replace(new DependencyInjectionSourceFacts('file:///source', [$service], [$parameter], [$reference], [$parent, $child]));

        self::assertSame([$service], $index->serviceDeclarations('app.service'));
        self::assertSame([$parameter], $index->parameterDeclarations('app.parameter'));
        self::assertSame([$reference], $index->references(DependencyInjectionSymbolKind::Service, 'app.service'));
        self::assertSame([$service], $index->decoratorsOf('app.inner'));
        self::assertSame(['app.service'], $index->serviceIds());
        self::assertSame(['app.parameter'], $index->parameterNames());
        self::assertSame([$child], $index->classDeclarations('\\App\\ChildService'));
        self::assertTrue($index->isSubclassOf('App\\ChildService', 'App\\ParentService'));
    }

    public function testInvalidatesIndexesWhenAnOverlayChanges(): void
    {
        $range = $this->range();
        $index = new DependencyInjectionSourceIndex();
        $index->replace(new DependencyInjectionSourceFacts('file:///source', [new ServiceDeclaration('first', 'file:///services.yaml', $range)]));
        self::assertSame(['first'], $index->serviceIds());

        $second = new ServiceDeclaration('second', 'file:///services.yaml', $range);
        $index->overlay(new DependencyInjectionSourceFacts('file:///source', [$second]));

        self::assertSame(['second'], $index->serviceIds());
        self::assertSame([], $index->serviceDeclarations('first'));
        self::assertSame([$second], $index->serviceDeclarations('second'));
    }

    private function range(): Range
    {
        return new Range(new Position(0, 0), new Position(0, 1));
    }
}
