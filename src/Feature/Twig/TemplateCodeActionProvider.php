<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\UriToPathConverter;

final class TemplateCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly TemplateReferenceExtractor $extractor,
        private readonly TemplateIndexRegistry $indexes,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly ProjectPathResolver $pathResolver,
    ) {
    }

    public function actions(array $params): ?array
    {
        $request = $this->documentContextResolver->resolveDocument($params);
        $context = $params['context'] ?? null;
        if (null === $request || !\is_array($context) || !$this->pathResolver->isApplicationOwned($request->project, $request->document->uri())) {
            return null;
        }

        $references = $this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text());
        $actions = [];
        foreach (\is_array($context['diagnostics'] ?? null) ? $context['diagnostics'] : [] as $diagnostic) {
            if (!\is_array($diagnostic) || 'template.not_found' !== ($diagnostic['code'] ?? null)) {
                continue;
            }
            $range = $diagnostic['range'] ?? null;
            if (!\is_array($range)) {
                continue;
            }
            foreach ($references as $reference) {
                if (!$this->sameRange($reference, $range)
                    || null !== $this->indexes->forProject($request->project)->get($reference->name())
                ) {
                    continue;
                }
                $path = $this->path($request->project->rootPath(), $reference->name());
                if (null === $path || is_file($path)) {
                    continue;
                }
                $uri = $this->uriToPathConverter->toUri($path);
                if (!$this->pathResolver->isApplicationOwned($request->project, $uri)) {
                    continue;
                }
                $actions[] = [
                    'title' => \sprintf('Create template "%s"', $reference->name()),
                    'kind' => 'quickfix',
                    'diagnostics' => [$diagnostic],
                    'isPreferred' => true,
                    'edit' => ['documentChanges' => [[
                        'kind' => 'create',
                        'uri' => $uri,
                    ]]],
                ];
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

    /** @param array<array-key, mixed> $range */
    private function sameRange(TemplateReference $reference, array $range): bool
    {
        $start = $range['start'] ?? null;
        $end = $range['end'] ?? null;

        return \is_array($start)
            && \is_array($end)
            && $reference->range()->start()->line() === ($start['line'] ?? null)
            && $reference->range()->start()->character() === ($start['character'] ?? null)
            && $reference->range()->end()->line() === ($end['line'] ?? null)
            && $reference->range()->end()->character() === ($end['character'] ?? null);
    }
}
