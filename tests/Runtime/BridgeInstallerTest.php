<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\BridgeInstaller;

final class BridgeInstallerTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testAcceptsAnEquivalentConcurrentInstallation(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        $module = $this->temporaryDirectory.'/bridge/sections/routes.php';
        mkdir(\dirname($module), 0777, true);
        file_put_contents($source, "<?php require __DIR__.'/bridge/sections/routes.php';");
        file_put_contents($module, '<?php function routes(): array { return []; }');
        $installer = new BridgeInstaller($source, 'test', new ConcurrentBridgeFilesystem());
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');

        $bridge = $installer->install($project);

        self::assertFileExists($bridge);
        self::assertFileExists(\dirname($bridge).'/bridge/sections/routes.php');
    }

    public function testInstallsBridgeBundleAtomicallyInsideProject(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        $module = $this->temporaryDirectory.'/bridge/sections/routes.php';
        mkdir(\dirname($module), 0777, true);
        file_put_contents($source, "<?php require __DIR__.'/bridge/sections/routes.php';");
        file_put_contents($module, '<?php function routes(): array { return []; }');
        $installer = new BridgeInstaller($source, 'test', new Filesystem());
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');

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
