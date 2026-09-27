<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

final class ProjectTwigCallableSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(
        private readonly TwigCallableRuntimeIndexRegistry $indexes,
        private readonly UriToPathConverter $uriConverter,
    ) {
    }

    public function section(): string
    {
        return 'twig';
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $declarations = [];
        foreach ($section->items('callables', 'kind', 'name', 'class', 'method', 'declaringClass', 'file') as $item) {
            $kind = TwigCallableKind::tryFrom($item->string('kind'));
            if (null === $kind) {
                continue;
            }
            $declarations[] = new TwigCallableRuntimeDeclaration(
                $kind,
                $item->string('name'),
                $item->string('class'),
                $item->string('method'),
                $item->string('declaringClass'),
                $this->uriConverter->toUri($item->path('file')),
                needsCharset: $item->bool('needsCharset'),
                needsEnvironment: $item->bool('needsEnvironment'),
                needsContext: $item->bool('needsContext'),
                needsIsSandboxed: $item->bool('needsIsSandboxed'),
                variadic: $item->bool('variadic'),
            );
        }
        $this->indexes->forProject($project)->replace(...$declarations);
    }
}
