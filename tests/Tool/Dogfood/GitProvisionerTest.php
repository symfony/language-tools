<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tools\Dogfood\GitProvisioner;
use Symfony\Lsp\Tools\Dogfood\NativeProcessRunner;
use Symfony\Lsp\Tools\Dogfood\ProjectConfiguration;
use Symfony\Lsp\Tools\Dogfood\ProvisioningException;

final class GitProvisionerTest extends TestCase
{
    private string $directory;
    private string $origin;
    private NativeProcessRunner $processes;
    private GitProvisioner $provisioner;

    protected function setUp(): void
    {
        $this->directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-dogfood-'.bin2hex(random_bytes(8)));
        $this->origin = Path::join($this->directory, 'origin');
        (new Filesystem())->mkdir($this->origin);
        $this->processes = new NativeProcessRunner();
        $this->git(['init', '--initial-branch=main'], $this->origin);
        $this->git(['config', 'user.email', 'dogfood@example.com'], $this->origin);
        $this->git(['config', 'user.name', 'Dogfood'], $this->origin);
        $this->provisioner = new GitProvisioner(
            $this->processes,
            new Filesystem(),
            Path::join($this->directory, 'mirrors'),
            Path::join($this->directory, 'checkouts'),
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testProvisionsCleanCheckoutAtPinnedRevision(): void
    {
        $pinned = $this->commit('composer.json', '{"name": "acme/app"}');
        $this->commit('composer.json', '{"name": "acme/app", "type": "project"}');

        $checkout = $this->provisioner->provision($this->configuration($pinned));

        self::assertSame('{"name": "acme/app"}', file_get_contents(Path::join($checkout, 'composer.json')));
        self::assertSame($pinned, $this->head($checkout));
        self::assertSame('', trim($this->processes->run(['git', '-C', $checkout, 'status', '--porcelain'])->standardOutput));
    }

    public function testProvisionReplacesAPreviousDirtyCheckout(): void
    {
        $pinned = $this->commit('composer.json', '{}');
        $checkout = $this->provisioner->provision($this->configuration($pinned));
        file_put_contents(Path::join($checkout, 'dirty.txt'), 'dirty');
        file_put_contents(Path::join($checkout, 'composer.json'), 'changed');

        $checkout = $this->provisioner->provision($this->configuration($pinned));

        self::assertFileDoesNotExist(Path::join($checkout, 'dirty.txt'));
        self::assertSame('{}', file_get_contents(Path::join($checkout, 'composer.json')));
    }

    public function testProvisionUpdatesTheMirrorForNewRevisions(): void
    {
        $first = $this->commit('composer.json', '{}');
        $this->provisioner->provision($this->configuration($first));
        $second = $this->commit('composer.json', '{"type": "project"}');

        $checkout = $this->provisioner->provision($this->configuration($second));

        self::assertSame($second, $this->head($checkout));
    }

    public function testProvisionRejectsUnknownRevisions(): void
    {
        $this->commit('composer.json', '{}');

        $this->expectException(ProvisioningException::class);
        $this->expectExceptionMessage('does not exist in');

        $this->provisioner->provision($this->configuration(str_repeat('b', 40)));
    }

    public function testReleaseRemovesTheCheckout(): void
    {
        $pinned = $this->commit('composer.json', '{}');
        $configuration = $this->configuration($pinned);
        $checkout = $this->provisioner->provision($configuration);

        $this->provisioner->release($configuration);

        self::assertDirectoryDoesNotExist($checkout);
    }

    private function configuration(string $revision): ProjectConfiguration
    {
        return new ProjectConfiguration('origin', $this->origin, $revision, null, 'dev', 'composer', false, 120);
    }

    private function commit(string $file, string $contents): string
    {
        file_put_contents(Path::join($this->origin, $file), $contents);
        $this->git(['add', $file], $this->origin);
        $this->git(['commit', '-m', 'Update '.$file], $this->origin);

        return $this->head($this->origin);
    }

    private function head(string $repository): string
    {
        $result = $this->processes->run(['git', '-C', $repository, 'rev-parse', 'HEAD']);
        self::assertSame(0, $result->exitCode);

        return trim($result->standardOutput);
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments, string $directory): void
    {
        $result = $this->processes->run(['git', '-C', $directory, ...$arguments]);
        self::assertSame(0, $result->exitCode, $result->errorOutput);
    }
}
