<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\CodeActionRequest;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TemplateCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly TemplateReferenceExtractor $extractor,
        private readonly TemplateIndexRegistry $indexes,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly ProjectPathResolver $pathResolver,
        private readonly LspProtocolMapper $protocol,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly UnknownNameCodeActionBuilder $unknownNames,
    ) {
    }

    public function actions(CodeActionRequest $request): array
    {
        if ([] === $request->diagnostics('template.not_found')
            || !$this->pathResolver->isApplicationOwned($request->project, $request->document->uri)
        ) {
            return [];
        }

        $references = $this->extractor->extract($request->source, $this->classIndexes->forProject($request->project));
        $index = $this->indexes->forProject($request->project);
        $actions = [];
        foreach ($request->diagnostics('template.not_found') as $diagnostic) {
            foreach ($references as $reference) {
                if (!$reference->range->equals($diagnostic->range) || null !== $index->get($reference->name)) {
                    continue;
                }
                $replacements = $index->isComplete() ? $this->unknownNames->replacements(
                    $request->document,
                    $diagnostic->diagnostic,
                    $reference->range,
                    $reference->name,
                    array_map(static fn (TemplateDeclaration $template): string => $template->name, $index->matching('')),
                ) : [];
                array_push($actions, ...$replacements);
                $path = $this->path($request->project->rootPath, $reference->name);
                if (null === $path || is_file($path)) {
                    continue;
                }
                $uri = $this->uriToPathConverter->toUri($path);
                if (!$this->pathResolver->isApplicationOwned($request->project, $uri)) {
                    continue;
                }
                $actions[] = $this->protocol->quickFix(
                    \sprintf('Create template "%s"', $reference->name),
                    $diagnostic->diagnostic,
                    [['kind' => 'create', 'uri' => $uri]],
                    [] === $replacements,
                );
                break;
            }
        }

        return $actions;
    }

    private function path(string $root, string $name): ?string
    {
        if ('' === $name || str_starts_with($name, '@') || str_starts_with($name, '/') || str_contains($name, '\\')) {
            return null;
        }
        $parts = explode('/', $name);
        if (\in_array('..', $parts, true) || \in_array('', $parts, true)) {
            return null;
        }

        return Path::join($root, 'templates', $name);
    }
}
