<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Twig\ProjectTwigComponentSnapshotLoader;
use Symfony\Lsp\Feature\Twig\TwigComponentIndexRegistry;
use Symfony\Lsp\Project\Project;

final class ProjectTwigComponentSnapshotLoaderTest extends TestCase
{
    public function testLoadsRuntimeComponentNames(): void
    {
        $indexes = new TwigComponentIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $loader = new ProjectTwigComponentSnapshotLoader($indexes);

        $loader->load($project, ['sections' => ['twig_components' => [
            'complete' => true,
            'names' => ['ux:icon', 'Alert', 42, ['nested']],
            'anonymousTemplateDirectory' => 'ui',
        ]]]);

        $index = $indexes->forProject($project);
        self::assertTrue($index->isRuntimeComplete());
        self::assertTrue($index->hasRuntimeName('ux:icon'));
        self::assertTrue($index->hasRuntimeName('Alert'));
        self::assertFalse($index->hasRuntimeName('42'));
        self::assertSame('ui', $index->anonymousTemplateDirectory());
    }

    public function testKeepsIncompleteSectionsConservative(): void
    {
        $indexes = new TwigComponentIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $loader = new ProjectTwigComponentSnapshotLoader($indexes);

        $loader->load($project, ['sections' => ['twig_components' => [
            'complete' => false,
            'names' => ['ux:icon'],
            'anonymousTemplateDirectory' => '',
        ]]]);

        $index = $indexes->forProject($project);
        self::assertFalse($index->isRuntimeComplete());
        self::assertTrue($index->hasRuntimeName('ux:icon'));
        self::assertSame('components', $index->anonymousTemplateDirectory());
    }

    public function testIgnoresMalformedSections(): void
    {
        $indexes = new TwigComponentIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $loader = new ProjectTwigComponentSnapshotLoader($indexes);

        $loader->load($project, ['sections' => ['twig_components' => ['names' => 'invalid']]]);
        $loader->load($project, ['sections' => 'invalid']);
        $loader->load($project, []);

        self::assertFalse($indexes->forProject($project)->isRuntimeComplete());
    }
}
