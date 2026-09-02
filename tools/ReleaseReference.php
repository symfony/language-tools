<?php

namespace Symfony\Lsp\Tools;

require_once __DIR__.'/ReleaseVersion.php';

final class ReleaseReference
{
    public readonly string $embeddedVersion;

    public function __construct(
        public readonly string $type,
        public readonly string $name,
    ) {
        if (!\in_array($type, ['branch', 'tag'], true)) {
            throw new \InvalidArgumentException('The release reference type must be "branch" or "tag".');
        }
        if ('' === $name || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new \InvalidArgumentException('The release reference name must be a non-empty path component.');
        }

        if ('branch' === $type) {
            $this->embeddedVersion = 'dev';

            return;
        }
        if (!str_starts_with($name, 'v')) {
            throw new \InvalidArgumentException('A release tag must use the vX.Y.Z or vX.Y.Z-PRERELEASE format.');
        }

        try {
            $this->embeddedVersion = (new ReleaseVersion(substr($name, 1)))->value();
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException('A release tag must use the vX.Y.Z or vX.Y.Z-PRERELEASE format.', 0, $exception);
        }
    }
}
