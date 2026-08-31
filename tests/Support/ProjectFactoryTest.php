<?php

namespace Symfony\Lsp\Tests\Support;

use PHPUnit\Framework\TestCase;

final class ProjectFactoryTest extends TestCase
{
    public function testCreatesCanonicalProjectsAndPopulatedRegistries(): void
    {
        $factory = new ProjectFactory();
        $project = $factory->create('/workspace/my app');
        $registry = $factory->registry($project);

        self::assertSame('/workspace/my app', $project->rootPath);
        self::assertSame('file:///workspace/my%20app', $project->rootUri);
        self::assertSame('^8.0', $project->frameworkBundleConstraint);
        self::assertSame([$project], $registry->all());
    }
}
