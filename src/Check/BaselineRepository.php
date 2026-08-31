<?php

namespace Symfony\Lsp\Check;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Project\InvalidConfigurationException;

final class BaselineRepository
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly BaselineCodec $codec,
    ) {
    }

    public function resolve(string $workspace, string $displayPath): BaselineFile
    {
        $workspace = Path::canonicalize($workspace);
        $path = Path::canonicalize(Path::isAbsolute($displayPath) ? $displayPath : Path::join($workspace, $displayPath));
        if ($workspace !== $path && !Path::isBasePath($workspace, $path)) {
            throw new InvalidConfigurationException('The baseline path must be inside the workspace.');
        }
        $realPath = realpath($path);
        $realWorkspace = realpath($workspace);
        $ancestor = \dirname($path);
        while (!file_exists($ancestor) && $ancestor !== \dirname($ancestor)) {
            $ancestor = \dirname($ancestor);
        }
        $realParent = realpath($ancestor);
        if (false !== $realWorkspace) {
            $realWorkspace = Path::canonicalize($realWorkspace);
            foreach ([$realPath, $realParent] as $resolved) {
                if (false === $resolved) {
                    continue;
                }
                $resolved = Path::canonicalize($resolved);
                if ($realWorkspace !== $resolved && !Path::isBasePath($realWorkspace, $resolved)) {
                    throw new InvalidConfigurationException('The baseline path resolves outside the workspace.');
                }
            }
        }

        return new BaselineFile(
            $path,
            Path::makeRelative($path, $workspace),
            $displayPath,
        );
    }

    /** @return list<BaselineEntry> */
    public function load(BaselineFile $file): array
    {
        if (!is_file($file->path)) {
            throw new InvalidConfigurationException(\sprintf('The baseline "%s" does not exist.', $file->displayPath));
        }
        if (!is_readable($file->path)) {
            throw new InvalidConfigurationException(\sprintf('The baseline "%s" is unreadable.', $file->displayPath));
        }
        $contents = file_get_contents($file->path);
        if (false === $contents) {
            throw new InvalidConfigurationException(\sprintf('The baseline "%s" is not valid JSON.', $file->displayPath));
        }

        return $this->codec->decode($contents, $file->displayPath);
    }

    /** @param list<BaselineEntry> $entries */
    public function create(BaselineFile $file, array $entries): void
    {
        if (is_file($file->path)) {
            throw new InvalidConfigurationException(\sprintf('The baseline "%s" already exists; use --refresh-baseline to replace it.', $file->displayPath));
        }

        $contents = $this->codec->encode($entries);
        $this->filesystem->mkdir(\dirname($file->path));
        $stream = @fopen($file->path, 'x');
        if (false === $stream) {
            if (is_file($file->path)) {
                throw new InvalidConfigurationException(\sprintf('The baseline "%s" already exists; use --refresh-baseline to replace it.', $file->displayPath));
            }

            throw new IOException(\sprintf('Failed to create file "%s".', $file->path), 0, null, $file->path);
        }

        $created = false;
        try {
            $length = \strlen($contents);
            $offset = 0;
            while ($offset < $length) {
                $written = @fwrite($stream, substr($contents, $offset, 8192));
                if (false === $written || 0 === $written) {
                    throw new IOException(\sprintf('Failed to write file "%s".', $file->path), 0, null, $file->path);
                }
                $offset += $written;
            }
            if (!@fclose($stream)) {
                throw new IOException(\sprintf('Failed to close file "%s".', $file->path), 0, null, $file->path);
            }
            $created = true;
        } finally {
            if (\is_resource($stream)) {
                @fclose($stream);
            }
            if (!$created) {
                $this->filesystem->remove($file->path);
            }
        }
    }

    /** @param list<BaselineEntry> $entries */
    public function refresh(BaselineFile $file, array $entries): void
    {
        if (!is_file($file->path)) {
            throw new InvalidConfigurationException(\sprintf('The baseline "%s" does not exist; use --generate-baseline to create it.', $file->displayPath));
        }

        $this->filesystem->dumpFile($file->path, $this->codec->encode($entries));
    }
}
