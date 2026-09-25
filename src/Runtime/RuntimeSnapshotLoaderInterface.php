<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;

/**
 * Loads one section of a runtime snapshot into the indexes it feeds.
 *
 * The bridge returns a section whenever it could describe the application, and
 * reports a failure as an error instead, so loading a section always replaces
 * what the previous one loaded, and a key it does not carry is an empty set.
 */
interface RuntimeSnapshotLoaderInterface
{
    public function section(): string;

    public function load(Project $project, SnapshotSection $section): void;
}
