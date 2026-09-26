<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\BridgeInstaller;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class BridgeInstallerTest extends TestCase
{
    private TestWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace('symfony-lsp-');
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testAcceptsAnEquivalentConcurrentInstallation(): void
    {
        $source = $this->workspace->path('source.php');
        $module = $this->workspace->path('bridge/sections/routes.php');
        mkdir(\dirname($module), 0777, true);
        file_put_contents($source, "<?php require __DIR__.'/bridge/sections/routes.php';");
        file_put_contents($module, '<?php function routes(): array { return []; }');
        $installer = new BridgeInstaller($source, 'test', new ConcurrentBridgeFilesystem());
        $project = new Project($this->workspace->rootPath, 'file://'.$this->workspace->rootPath);

        $bridge = $installer->install($project);

        self::assertFileExists($bridge);
        self::assertFileExists(\dirname($bridge).'/bridge/sections/routes.php');
    }

    public function testInstallsBridgeBundleAtomicallyInsideProject(): void
    {
        $source = $this->workspace->path('source.php');
        $module = $this->workspace->path('bridge/sections/routes.php');
        mkdir(\dirname($module), 0777, true);
        file_put_contents($source, "<?php require __DIR__.'/bridge/sections/routes.php';");
        file_put_contents($module, '<?php function routes(): array { return []; }');
        $installer = new BridgeInstaller($source, 'test', new Filesystem());
        $project = new Project($this->workspace->rootPath, 'file://'.$this->workspace->rootPath);

        $first = $installer->install($project);
        $second = $installer->install($project);

        self::assertSame($first, $second);
        self::assertMatchesRegularExpression('{/var/symfony-lsp/test/[a-f0-9]{64}/bridge\.php$}', $first);
        self::assertSame(
            "<?php require __DIR__.'/bridge/sections/routes.php';",
            file_get_contents($first),
        );
        self::assertSame(
            '<?php function routes(): array { return []; }',
            file_get_contents(\dirname($first).'/bridge/sections/routes.php'),
        );

        file_put_contents($module, '<?php function routes(): array { return ["updated"]; }');
        $updated = $installer->install($project);

        self::assertNotSame($first, $updated);
        self::assertSame(
            '<?php function routes(): array { return ["updated"]; }',
            file_get_contents(\dirname($updated).'/bridge/sections/routes.php'),
        );
    }
}

final class ConcurrentBridgeFilesystem extends Filesystem
{
    public function dumpFile(string $filename, $content): void
    {
        parent::dumpFile($filename, $content);
        if (!str_ends_with($filename, '/bridge/sections/routes.php')) {
            return;
        }

        $temporary = \dirname($filename, 3);
        if (preg_match('/^\.bridge-([a-f0-9]{64})-[a-f0-9]{16}$/', basename($temporary), $match)) {
            $this->mirror($temporary, \dirname($temporary).'/'.$match[1]);
        }
    }
}
