<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Project\Project;

interface SourceIndexProviderInterface
{
    public function name(): string;

    /** @return list<string> */
    public function payloadClasses(): array;

    public function begin(Project $project): void;

    public function index(Project $project, SourceDocument $document): ?SourceFactsInterface;

    public function restore(Project $project, mixed $data): void;

    public function finish(Project $project): void;

    public function replace(Project $project, SourceDocument $document): ?SourceFactsInterface;

    /** @return list<mixed> */
    public function runtimeDeclarations(mixed $data): array;

    public function remove(Project $project, string $uri): void;

    public function overlay(Project $project, Document $document, SourceParseHealth $health): void;

    public function removeOverlay(Project $project, string $uri): void;
}
