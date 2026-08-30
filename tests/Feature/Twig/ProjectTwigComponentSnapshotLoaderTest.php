<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Twig\ProjectTwigComponentSnapshotLoader;
use Symfony\Lsp\Feature\Twig\TwigComponentIndexRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class ProjectTwigComponentSnapshotLoaderTest extends TestCase
{
    public function testLoadsRuntimeComponentNames(): void
    {
        $indexes = new TwigComponentIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $loader = new ProjectTwigComponentSnapshotLoader($indexes, new ContainerPathMapper(new RuntimeConfiguration()), new UriToPathConverter());

        $loader->load($project, ['sections' => ['twig_components' => [
            'complete' => true,
            'names' => ['ux:icon', 'Alert', 42, ['nested']],
            'caseInsensitiveNames' => ['ux:icon', 42, ['nested']],
            'anonymousTemplateDirectory' => 'ui',
            'components' => [
                [
                    'name' => 'UX:Icon',
                    'class' => 'Symfony\UX\Icons\Twig\UXIconComponent',
                    'file' => '/workspace/vendor/symfony/ux-icons/src/Twig/UXIconComponent.php',
                    'template' => '@UXIcons/Icon.html.twig',
                    'live' => false,
                ],
                ['name' => 'broken', 'class' => 'App\Broken', 'file' => null],
            ],
        ]]]);

        $index = $indexes->forProject($project);
        // UX Icons registers UX:Icon with a case-insensitive renderer alias
        $vendor = $index->get('ux:icon');
        self::assertNotNull($vendor);
        self::assertSame('file:///workspace/vendor/symfony/ux-icons/src/Twig/UXIconComponent.php', $vendor->uri);
        self::assertSame('Symfony\UX\Icons\Twig\UXIconComponent', $vendor->className);
        self::assertSame('@UXIcons/Icon.html.twig', $vendor->template);
        self::assertNotNull($index->get('UX:Icon'));
        self::assertNull($index->get('broken'));
        self::assertTrue($index->isRuntimeComplete());
        self::assertTrue($index->hasRuntimeName('ux:icon'));
        self::assertTrue($index->hasRuntimeName('UX:Icon'));
        self::assertTrue($index->hasRuntimeName('uX:iCoN'));
        self::assertTrue($index->hasRuntimeName('Alert'));
        self::assertFalse($index->hasRuntimeName('alert'));
        self::assertFalse($index->hasRuntimeName('42'));
        self::assertSame('ui', $index->anonymousTemplateDirectory());
    }

    public function testClearsRuntimeNamesWhenTheIntegrationIsUnavailable(): void
    {
        $indexes = new TwigComponentIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $loader = new ProjectTwigComponentSnapshotLoader($indexes, new ContainerPathMapper(new RuntimeConfiguration()), new UriToPathConverter());
        $indexes->forProject($project)->replaceRuntime(true, ['stale_component'], 'ui', ['stale_component']);

        $loader->load($project, ['sections' => ['twig_components' => [
            'complete' => true,
            'names' => [],
            'anonymousTemplateDirectory' => 'components',
        ]]]);

        $index = $indexes->forProject($project);
        self::assertTrue($index->isRuntimeComplete());
        self::assertSame([], $index->runtimeNames());
        self::assertFalse($index->hasRuntimeName('STALE_COMPONENT'));
        self::assertSame('components', $index->anonymousTemplateDirectory());
    }

    public function testKeepsIncompleteSectionsConservative(): void
    {
        $indexes = new TwigComponentIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $loader = new ProjectTwigComponentSnapshotLoader($indexes, new ContainerPathMapper(new RuntimeConfiguration()), new UriToPathConverter());

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
        $loader = new ProjectTwigComponentSnapshotLoader($indexes, new ContainerPathMapper(new RuntimeConfiguration()), new UriToPathConverter());

        $loader->load($project, ['sections' => ['twig_components' => ['names' => 'invalid']]]);
        $loader->load($project, ['sections' => 'invalid']);
        $loader->load($project, []);

        self::assertFalse($indexes->forProject($project)->isRuntimeComplete());
    }
}
