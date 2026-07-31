<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
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
        $this->removeDirectory($this->temporaryDirectory);
    }

    public function testInstallsBridgeBundleAtomicallyInsideProject(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        $module = $this->temporaryDirectory.'/bridge/sections/routes.php';
        mkdir(\dirname($module), 0777, true);
        file_put_contents($source, "<?php require __DIR__.'/bridge/sections/routes.php';");
        file_put_contents($module, '<?php function routes(): array { return []; }');
        $installer = new BridgeInstaller($source, 'test');
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

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($directory);
    }
}
