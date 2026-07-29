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
        $bridgeDirectory = $this->temporaryDirectory.'/var/symfony-lsp/test';
        @unlink($bridgeDirectory.'/bridge.php');
        @rmdir($bridgeDirectory);
        @rmdir(\dirname($bridgeDirectory));
        @rmdir(\dirname($bridgeDirectory, 2));
        @rmdir($this->temporaryDirectory);
    }

    public function testInstallsBridgeAtomicallyInsideProject(): void
    {
        $source = $this->temporaryDirectory.'/source.php';
        file_put_contents($source, '<?php echo "bridge";');
        $installer = new BridgeInstaller($source, 'test');
        $project = new Project($this->temporaryDirectory, 'file://'.$this->temporaryDirectory, '^8.0');

        $installer->initialize($project);
        $installer->initialize($project);

        self::assertSame(
            '<?php echo "bridge";',
            file_get_contents($this->temporaryDirectory.'/var/symfony-lsp/test/bridge.php'),
        );

        @unlink($source);
    }
}
