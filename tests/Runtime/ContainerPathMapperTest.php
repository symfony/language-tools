<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class ContainerPathMapperTest extends TestCase
{
    public function testKeepsPathsUntouchedWithoutAContainerProjectRoot(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $mapper = new ContainerPathMapper(new RuntimeConfiguration());

        self::assertSame('/workspace/templates', $mapper->toContainer($project, '/workspace/templates'));
        self::assertSame('/app/templates', $mapper->toHost($project, '/app/templates'));
    }

    public function testMapsProjectPathsBetweenHostAndContainer(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $configuration = new RuntimeConfiguration();
        $configuration->configure(['containerProjectRoot' => '/app']);
        $mapper = new ContainerPathMapper($configuration);

        self::assertSame('/app', $mapper->toContainer($project, '/workspace'));
        self::assertSame('/app/var/symfony-lsp/v1/bridge.php', $mapper->toContainer($project, '/workspace/var/symfony-lsp/v1/bridge.php'));
        self::assertSame('/workspace', $mapper->toHost($project, '/app'));
        self::assertSame('/workspace/templates/base.html.twig', $mapper->toHost($project, '/app/templates/base.html.twig'));
    }

    public function testKeepsPathsOutsideTheMappedRootsUntouched(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $configuration = new RuntimeConfiguration();
        $configuration->configure(['containerProjectRoot' => '/app']);
        $mapper = new ContainerPathMapper($configuration);

        self::assertSame('/elsewhere/tool.php', $mapper->toContainer($project, '/elsewhere/tool.php'));
        self::assertSame('/usr/local/lib/php/extra.php', $mapper->toHost($project, '/usr/local/lib/php/extra.php'));
        self::assertSame('/application/config.yaml', $mapper->toHost($project, '/application/config.yaml'));
        self::assertSame('templates/base.html.twig', $mapper->toHost($project, 'templates/base.html.twig'));
    }

    public function testMapsWindowsHostPathsToPosixContainerPaths(): void
    {
        $project = new Project('C:/Users/nath/api', 'file:///C:/Users/nath/api', '^8.0');
        $configuration = new RuntimeConfiguration();
        $configuration->configure(['containerProjectRoot' => '/app']);
        $mapper = new ContainerPathMapper($configuration);

        self::assertSame('/app/var/symfony-lsp/v1/bridge.php', $mapper->toContainer($project, 'C:/Users/nath/api/var/symfony-lsp/v1/bridge.php'));
        self::assertSame('C:/Users/nath/api/templates', $mapper->toHost($project, '/app/templates'));
    }

    public function testUsesTheProjectContainerProjectRootOverTheGlobalOne(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $configuration = new RuntimeConfiguration();
        $configuration->configure(['containerProjectRoot' => '/app']);
        $configuration->configureProject($project, ['containerProjectRoot' => '/srv/api']);
        $mapper = new ContainerPathMapper($configuration);

        self::assertSame('/srv/api/composer.json', $mapper->toContainer($project, '/workspace/composer.json'));
        self::assertSame('/workspace/composer.json', $mapper->toHost($project, '/srv/api/composer.json'));
    }

    public function testClearsTheGlobalContainerProjectRootForAnEmptyProjectValue(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $configuration = new RuntimeConfiguration();
        $configuration->configure(['containerProjectRoot' => '/app']);
        $configuration->configureProject($project, ['containerProjectRoot' => '']);
        $mapper = new ContainerPathMapper($configuration);

        self::assertSame('/workspace/composer.json', $mapper->toContainer($project, '/workspace/composer.json'));
    }

    public function testIgnoresRelativeContainerProjectRoots(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $configuration = new RuntimeConfiguration();
        $configuration->configure(['containerProjectRoot' => 'app']);
        $mapper = new ContainerPathMapper($configuration);

        self::assertSame('/workspace/composer.json', $mapper->toContainer($project, '/workspace/composer.json'));
    }
}
