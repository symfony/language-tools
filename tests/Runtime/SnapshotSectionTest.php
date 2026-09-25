<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Tests\Support\SnapshotSections;

final class SnapshotSectionTest extends TestCase
{
    public function testReadsScalarsAndFallsBackWhenAValueHasAnotherType(): void
    {
        $section = SnapshotSections::of($this->project(), [
            'name' => 'homepage',
            'class' => 42,
            'lazy' => false,
            'priority' => -10,
            'depth' => '3',
            'example' => ['nested' => true],
        ]);

        self::assertSame('homepage', $section->string('name'));
        self::assertSame('homepage', $section->optionalString('name'));
        self::assertSame('', $section->string('class'));
        self::assertNull($section->optionalString('class'));
        self::assertNull($section->optionalString('missing'));
        self::assertFalse($section->bool('lazy', true));
        self::assertFalse($section->optionalBool('lazy'));
        self::assertNull($section->optionalBool('missing'));
        self::assertTrue($section->bool('missing', true));
        self::assertFalse($section->bool('missing'));
        self::assertSame(-10, $section->int('priority'));
        self::assertSame(0, $section->int('depth'));
        self::assertSame(7, $section->int('missing', 7));
        self::assertSame(['nested' => true], $section->value('example'));
        self::assertNull($section->value('missing'));
    }

    public function testReadsCompletenessOfTheSectionAndOfEachSet(): void
    {
        $section = SnapshotSections::of($this->project(), [
            'complete' => true,
            'assetsComplete' => true,
            'importMapComplete' => false,
        ]);

        self::assertTrue($section->complete());
        self::assertTrue($section->complete('assets'));
        self::assertFalse($section->complete('importMap'));
        self::assertFalse($section->complete('forms'));
        self::assertFalse(SnapshotSections::of($this->project())->complete());
    }

    public function testReportsASubsystemAsEnabledUntilASectionDeniesIt(): void
    {
        self::assertTrue(SnapshotSections::of($this->project())->enabled());
        self::assertTrue(SnapshotSections::of($this->project(), ['enabled' => true])->enabled());
        self::assertFalse(SnapshotSections::of($this->project(), ['enabled' => false])->enabled());
    }

    public function testReadsListsAndMapsWithoutTheEntriesOfAnotherType(): void
    {
        $section = SnapshotSections::of($this->project(), [
            'names' => ['first', 42, ['nested'], 'second'],
            'requirements' => ['page' => '\d+', 'slug' => 42, 7 => 'ignored'],
            'accepts' => ['null' => true, 'true' => false, 'string' => 'yes', 3 => true],
            'allowedValues' => ['text', 3, 1.5, true, null, ['nested']],
            'globals' => 'invalid',
        ]);

        self::assertSame(['first', 'second'], $section->strings('names'));
        self::assertSame([], $section->strings('globals'));
        self::assertSame([], $section->strings('missing'));
        self::assertSame(['page' => '\d+'], $section->stringMap('requirements'));
        self::assertSame(['null' => true, 'true' => false], $section->boolMap('accepts'));
        self::assertSame(['text', 3, 1.5, true, null], $section->scalars('allowedValues'));
        self::assertSame([], $section->scalars('missing'));
    }

    public function testReadsItemsWithoutTheOnesMissingARequiredString(): void
    {
        $section = SnapshotSections::of($this->project(), [
            'items' => [
                ['name' => 'homepage', 'path' => '/'],
                ['name' => 'blog'],
                ['path' => '/contact'],
                ['name' => 42, 'path' => '/invalid'],
                'invalid',
            ],
        ]);

        self::assertSame(['homepage', 'blog'], array_map(static fn ($item): string => $item->string('name'), $section->items('items', 'name')));
        self::assertSame(['homepage'], array_map(static fn ($item): string => $item->string('name'), $section->items('items', 'name', 'path')));
        self::assertSame([], $section->items('missing', 'name'));
        self::assertSame([], $section->items('items', 'unknown'));
    }

    public function testReadsNestedSectionsAndTheirItems(): void
    {
        $section = SnapshotSections::of($this->project(), [
            'entities' => [[
                'className' => 'App\Entity\Book',
                'fields' => [['name' => 'title'], ['type' => 'string']],
            ]],
            'tree' => ['name' => 'framework'],
            'prototype' => 'invalid',
        ]);

        $entity = $section->items('entities', 'className')[0] ?? null;
        self::assertNotNull($entity);
        self::assertSame(['title'], array_map(static fn ($field): string => $field->string('name'), $entity->items('fields', 'name')));
        self::assertSame('framework', $section->section('tree')?->string('name'));
        self::assertNull($section->section('prototype'));
        self::assertNull($section->section('missing'));
    }

    public function testMapsContainerPathsBackToTheHost(): void
    {
        $configuration = new RuntimeConfiguration();
        $configuration->configure(['containerProjectRoot' => '/app']);
        $section = SnapshotSections::of($this->project(), [
            'file' => '/app/src/Entity/Book.php',
            'outside' => '/opt/other/file.php',
            'invalid' => 42,
        ], $configuration);

        self::assertSame('/workspace/src/Entity/Book.php', $section->path('file'));
        self::assertSame('/workspace/src/Entity/Book.php', $section->optionalPath('file'));
        self::assertSame('/opt/other/file.php', $section->path('outside'));
        self::assertSame('', $section->path('invalid'));
        self::assertNull($section->optionalPath('invalid'));
        self::assertNull($section->optionalPath('missing'));
    }

    public function testMapsPathsOfNestedItemsTheSameWay(): void
    {
        $configuration = new RuntimeConfiguration();
        $configuration->configure(['containerProjectRoot' => '/app']);
        $section = SnapshotSections::of($this->project(), [
            'assets' => [['sourcePath' => '/app/assets/app.js']],
        ], $configuration);

        self::assertSame('/workspace/assets/app.js', $section->items('assets', 'sourcePath')[0]->path('sourcePath'));
    }

    private function project(): Project
    {
        return new Project('/workspace', 'file:///workspace');
    }
}
