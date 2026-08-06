<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\UriToPathConverter;

final class ProjectPathResolverTest extends TestCase
{
    #[DataProvider('relativePathProvider')]
    public function testResolvesUrisRelativeToTheProject(Project $project, string $uri, ?string $expected): void
    {
        self::assertSame($expected, (new ProjectPathResolver(new UriToPathConverter()))->relative($project, $uri));
    }

    /** @return iterable<string, array{Project, string, string|null}> */
    public static function relativePathProvider(): iterable
    {
        yield 'Unix path' => [new Project('/workspace/my app', 'file:///workspace/my%20app', '^8.0'), 'file:///workspace/my%20app/src/Controller.php', 'src/Controller.php'];
        yield 'Windows path' => [new Project('C:/workspace/app', 'file:///C:/workspace/app', '^8.0'), 'file:///C:/workspace/app/config/routes.yaml', 'config/routes.yaml'];
        yield 'outside project' => [new Project('/workspace/app', 'file:///workspace/app', '^8.0'), 'file:///workspace/application/src/Controller.php', null];
        yield 'non-file URI' => [new Project('/workspace/app', 'file:///workspace/app', '^8.0'), 'untitled:Untitled-1', null];
    }
}
