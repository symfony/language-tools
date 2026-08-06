<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

final class TemplateCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly TemplateReferenceExtractor $extractor,
        private readonly TemplateIndexRegistry $indexes,
        private readonly UriToPathConverter $uriToPathConverter,
    ) {
    }

    public function actions(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        $context = $params['context'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null) || !\is_array($context)) {
            return null;
        }
        $document = $this->documents->get($textDocument['uri']);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $document || null === $project) {
            return null;
        }

        $references = $this->extractor->extract($document->uri(), $document->languageId(), $document->text());
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
                    || null !== $this->indexes->forProject($project)->get($reference->name())
                ) {
                    continue;
                }
                $path = $this->path($project->rootPath(), $reference->name());
                if (null === $path || is_file($path)) {
                    continue;
                }
                $actions[] = [
                    'title' => \sprintf('Create template "%s"', $reference->name()),
                    'kind' => 'quickfix',
                    'diagnostics' => [$diagnostic],
                    'isPreferred' => true,
                    'edit' => ['documentChanges' => [[
                        'kind' => 'create',
                        'uri' => $this->uriToPathConverter->toUri($path),
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
