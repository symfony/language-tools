<?php

namespace Symfony\Lsp\Tests\Feature\Metadata;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Metadata\MetadataIndexRegistry;
use Symfony\Lsp\Feature\Metadata\ProjectMetadataSnapshotLoader;
use Symfony\Lsp\Project\Project;

final class ProjectMetadataSnapshotLoaderTest extends TestCase
{
    public function testLoadsFormAndConstraintMetadata(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $indexes = new MetadataIndexRegistry();
        (new ProjectMetadataSnapshotLoader($indexes))->load($project, ['sections' => ['metadata' => [
            'formsComplete' => true,
            'constraintsComplete' => true,
            'forms' => [[
                'class' => 'App\\Form\\PostType',
                'blockPrefix' => 'post',
                'options' => ['action', 'method'],
                'requiredOptions' => ['action'],
            ]],
            'constraints' => [[
                'name' => 'Length',
                'class' => 'Symfony\\Component\\Validator\\Constraints\\Length',
                'options' => ['max', 'min'],
            ]],
        ]]]);

        $index = $indexes->forProject($project);
        self::assertTrue($index->formsComplete());
        self::assertTrue($index->constraintsComplete());
        self::assertSame(['action', 'method'], $index->formType('App\\Form\\PostType')?->options());
        self::assertSame(['max', 'min'], $index->constraint('Length')?->options());
    }
}
