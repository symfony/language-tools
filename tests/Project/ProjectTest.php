<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\Project;

final class ProjectTest extends TestCase
{
    #[DataProvider('configPathProvider')]
    public function testResolvesPathsInsideTheConfigurationDirectory(?string $configPath, string $relativePath, ?string $configRelativePath, bool $route): void
    {
        $project = new Project('/workspace', 'file:///workspace', configPath: $configPath);

        self::assertSame($configRelativePath, $project->configRelativePath($relativePath));
        self::assertSame($route, $project->isRouteConfigPath($relativePath));
    }

    /** @return iterable<string, array{string|null, string, string|null, bool}> */
    public static function configPathProvider(): iterable
    {
        yield 'package' => ['config', 'config/packages/framework.yaml', 'packages/framework.yaml', false];
        yield 'route file' => ['config', 'config/routes.yaml', 'routes.yaml', true];
        yield 'route directory' => ['config', 'config/routes/admin.yaml', 'routes/admin.yaml', true];
        yield 'custom directory' => ['etc', 'etc/routes.yaml', 'routes.yaml', true];
        yield 'default directory with a custom one' => ['etc', 'config/routes.yaml', null, false];
        yield 'directory name prefix' => ['config', 'configuration/services.yaml', null, false];
        yield 'bundle directory' => ['config', 'src/AcmeBundle/config/services.yaml', null, false];
        yield 'outside the project' => [null, 'config/services.yaml', null, false];
    }
}
