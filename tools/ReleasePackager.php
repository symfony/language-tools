<?php

namespace Symfony\Lsp\Tools;

require_once __DIR__.'/InteractiveProcessRunner.php';
require_once __DIR__.'/ReleaseReference.php';

final class ReleasePackager
{
    private const REQUIRED_LICENSES = [
        'runtime/src_php-src_0.txt',
        'tree-sitter/tree-sitter-twig-LICENSE',
    ];

    public function __construct(
        private readonly string $root,
        private readonly InteractiveProcessRunner $processes,
    ) {
    }

    public function package(string $platform, ReleaseReference $reference): string
    {
        [$sourceExecutable, $packagedExecutable, $archiveExtension, $socketMode] = match ($platform) {
            'linux-x64', 'linux-arm64', 'macos-x64', 'macos-arm64' => ['build/symfony-lsp', 'symfony-lsp', 'tar.gz', false],
            'windows-x64' => ['build/symfony-lsp.exe', 'symfony-lsp.exe', 'zip', true],
            default => throw new \InvalidArgumentException('Unsupported release platform "'.$platform.'".'),
        };

        $packageName = 'symfony-lsp-'.$reference->name.'-'.$platform;
        $distDirectory = $this->root.'/dist';
        $packageDirectory = $distDirectory.'/'.$packageName;
        $this->removeTree($packageDirectory);
        if (!is_dir($packageDirectory) && !mkdir($packageDirectory, 0777, true) && !is_dir($packageDirectory)) {
            throw new \RuntimeException('Unable to create the release package directory.');
        }

        $this->copyFile($this->root.'/'.$sourceExecutable, $packageDirectory.'/'.$packagedExecutable);
        $this->copyFile($this->root.'/LICENSE', $packageDirectory.'/LICENSE');
        $this->copyFile($this->root.'/THIRD_PARTY_NOTICES.md', $packageDirectory.'/THIRD_PARTY_NOTICES.md');
        $this->copyTree($this->root.'/THIRD_PARTY_LICENSES', $packageDirectory.'/THIRD_PARTY_LICENSES');
        foreach (self::REQUIRED_LICENSES as $license) {
            if (!is_file($packageDirectory.'/THIRD_PARTY_LICENSES/'.$license)) {
                throw new \RuntimeException('Required release license "'.$license.'" is missing.');
            }
        }

        $smokeCommand = [\PHP_BINARY, $this->root.'/tools/smoke-test-server'];
        if ($socketMode) {
            $smokeCommand[] = '--socket';
        }
        $smokeCommand[] = $packageDirectory.'/'.$packagedExecutable;
        $smokeCommand[] = $reference->embeddedVersion;
        if (0 !== $this->processes->run($smokeCommand, $this->root)) {
            throw new \RuntimeException('The packaged server smoke test failed.');
        }

        $archivePath = $distDirectory.'/'.$packageName.'.'.$archiveExtension;
        $this->createArchive($packageDirectory, $archivePath, $archiveExtension);

        return $archivePath;
    }

    private function copyFile(string $source, string $destination): void
    {
        if (!is_file($source)) {
            throw new \RuntimeException('Required release package file "'.$source.'" is missing.');
        }
        if (!copy($source, $destination)) {
            throw new \RuntimeException('Unable to copy release package file "'.$source.'".');
        }
        $permissions = fileperms($source);
        if (false !== $permissions && !chmod($destination, $permissions & 0777)) {
            throw new \RuntimeException('Unable to preserve release package file permissions for "'.$source.'".');
        }
    }

    private function copyTree(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new \RuntimeException('Required release package directory "'.$source.'" is missing.');
        }
        if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
            throw new \RuntimeException('Unable to create release package directory "'.$destination.'".');
        }

        foreach (scandir($source) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $sourcePath = $source.'/'.$entry;
            $destinationPath = $destination.'/'.$entry;
            if (is_dir($sourcePath)) {
                $this->copyTree($sourcePath, $destinationPath);
            } else {
                $this->copyFile($sourcePath, $destinationPath);
            }
        }
    }

    private function createArchive(string $packageDirectory, string $archivePath, string $archiveExtension): void
    {
        @unlink($archivePath);
        $temporaryArchive = 'tar.gz' === $archiveExtension ? substr($archivePath, 0, -3) : $archivePath;
        @unlink($temporaryArchive);

        try {
            $archive = new \PharData($temporaryArchive);
            $this->addTreeToArchive($archive, $packageDirectory, basename($packageDirectory));
            if ('tar.gz' === $archiveExtension) {
                $compressed = $archive->compress(\Phar::GZ);
                unset($compressed);
            }
            unset($archive);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Unable to create the release archive.', 0, $exception);
        } finally {
            if ('tar.gz' === $archiveExtension) {
                @unlink($temporaryArchive);
            }
        }

        if (!is_file($archivePath)) {
            throw new \RuntimeException('The release archive was not created.');
        }
    }

    private function addTreeToArchive(\PharData $archive, string $source, string $archivePath): void
    {
        $archive->addEmptyDir(str_replace('\\', '/', $archivePath));
        foreach (scandir($source) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $sourcePath = $source.'/'.$entry;
            $entryArchivePath = $archivePath.'/'.$entry;
            if (is_dir($sourcePath)) {
                $this->addTreeToArchive($archive, $sourcePath, $entryArchivePath);
            } else {
                $archive->addFile($sourcePath, str_replace('\\', '/', $entryArchivePath));
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $this->removeTree($path.'/'.$entry);
            }
        }
        if (!rmdir($path)) {
            throw new \RuntimeException('Unable to remove the previous release package directory.');
        }
    }
}
