<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\SnapshotSection;

final class SnapshotSections
{
    /** @param array<array-key, mixed> $values */
    public static function of(Project $project, array $values = [], ?RuntimeConfiguration $configuration = null): SnapshotSection
    {
        return new SnapshotSection($project, new ContainerPathMapper($configuration ?? new RuntimeConfiguration()), $values);
    }
}
