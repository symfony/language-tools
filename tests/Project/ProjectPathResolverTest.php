<?php

namespace Symfony\Lsp\Tests\Project;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Tests\Support\ProjectPaths;

final class ProjectPathResolverTest extends TestCase
{
    #[DataProvider('relativePathProvider')]
    public function testResolvesUrisRelativeToTheProject(Project $project, string $uri, ?string $expected): void
    {
        self::assertSame($expected, ProjectPaths::resolver()->relative($project, $uri));
    }

    public function testKeepsExternalSymlinksReadOnlyWithoutRejectingInternalSymlinks(): void
    {
        $directory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        $root = $directory.'/project';
        mkdir($root.'/src', 0777, true);
        mkdir($root.'/templates', 0777, true);
        mkdir($root.'/vendor', 0777, true);
        mkdir($directory.'/external', 0777, true);
        file_put_contents($root.'/src/Internal.php', '<?php');
        file_put_contents($directory.'/external/External.php', '<?php');
        symlink($root.'/src/Internal.php', $root.'/src/InternalLink.php');
        symlink($directory.'/external/External.php', $root.'/src/ExternalLink.php');
        symlink($directory.'/external', $root.'/templates/external');
        symlink($directory.'/external', $root.'/vendor/symfony');
        $converter = new UriToPathConverter();
        $project = new Project($root, $converter->toUri($root));
        $resolver = ProjectPaths::resolver();

        try {
            self::assertTrue($resolver->isApplicationOwned($project, $converter->toUri($root.'/src/InternalLink.php')));
            self::assertTrue($resolver->isApplicationOwned($project, $converter->toUri($root.'/templates/New.html.twig')));
            self::assertFalse($resolver->isApplicationOwned($project, $converter->toUri($root.'/src/ExternalLink.php')));
            self::assertFalse($resolver->isApplicationOwned($project, $converter->toUri($root.'/templates/external/New.html.twig')));
            self::assertFalse($resolver->isApplicationOwned($project, $converter->toUri($root.'/vendor/symfony/External.php')));
        } finally {
            (new Filesystem())->remove($directory);
        }
    }

    public function testOwnsApplicationDirectoriesNamedLikeDependencyDirectories(): void
    {
        $root = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($root.'/templates/vendor', 0777, true);
        mkdir($root.'/vendor/acme', 0777, true);
        mkdir($root.'/var/cache', 0777, true);
        file_put_contents($root.'/.gitignore', "/var/\n/vendor/\n");
        $converter = new UriToPathConverter();
        $project = new Project($root, $converter->toUri($root));
        $resolver = ProjectPaths::resolver($converter);

        try {
            self::assertTrue($resolver->isApplicationOwned($project, $converter->toUri($root.'/templates/vendor/show.html.twig')));
            self::assertFalse($resolver->isApplicationOwned($project, $converter->toUri($root.'/vendor/acme/Thing.php')));
            self::assertFalse($resolver->isApplicationOwned($project, $converter->toUri($root.'/var/cache/app.php')));
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    /** @return iterable<string, array{Project, string, string|null}> */
    public static function relativePathProvider(): iterable
    {
        yield 'Unix path' => [new Project('/workspace/my app', 'file:///workspace/my%20app'), 'file:///workspace/my%20app/src/Controller.php', 'src/Controller.php'];
        yield 'Windows path' => [new Project('C:/workspace/app', 'file:///C:/workspace/app'), 'file:///C:/workspace/app/config/routes.yaml', 'config/routes.yaml'];
        yield 'outside project' => [new Project('/workspace/app', 'file:///workspace/app'), 'file:///workspace/application/src/Controller.php', null];
        yield 'non-file URI' => [new Project('/workspace/app', 'file:///workspace/app'), 'untitled:Untitled-1', null];
    }
}
