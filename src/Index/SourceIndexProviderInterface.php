<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Project\Project;

interface SourceIndexProviderInterface
{
    public function begin(Project $project): void;

    public function index(Project $project, SourceDocument $document): void;

    public function finish(Project $project): void;

    public function overlay(Project $project, Document $document): void;

    public function removeOverlay(Project $project, string $uri): void;
}
