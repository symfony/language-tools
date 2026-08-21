<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TranslationCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TranslationExtractor $extractor,
        private readonly TranslationIndexRegistry $indexes,
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

        $references = $this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text())->references();
        $actions = [];
        foreach (\is_array($context['diagnostics'] ?? null) ? $context['diagnostics'] : [] as $diagnostic) {
            if (!\is_array($diagnostic) || 'translation.not_found' !== ($diagnostic['code'] ?? null)) {
                continue;
            }
            $range = $diagnostic['range'] ?? null;
            if (!\is_array($range)) {
                continue;
            }
            foreach ($references as $reference) {
                if (!$this->sameRange($reference, $range)
                    || [] !== $this->indexes->forProject($request->project)->declarations($reference->domain(), $reference->key())
                ) {
                    continue;
                }
                $target = $this->target($request->project->rootPath(), $reference->domain());
                if (null === $target) {
                    continue;
                }
                $targetUri = $this->uri($target);
                if (!$this->pathResolver->isApplicationOwned($request->project, $targetUri)) {
                    continue;
                }
                $contents = file_get_contents($target);
                if (false === $contents) {
                    continue;
                }
                $position = $this->converter->toPosition($contents, \strlen($contents));
                $escapedKey = str_replace("'", "''", $reference->key());
                $newText = ('' === $contents || str_ends_with($contents, "\n") ? '' : "\n")."'{$escapedKey}': '{$escapedKey}'\n";
                $actions[] = [
                    'title' => \sprintf('Add translation "%s" to %s', $reference->key(), basename($target)),
                    'kind' => 'quickfix',
                    'diagnostics' => [$diagnostic],
                    'isPreferred' => true,
                    'edit' => ['documentChanges' => [[
                        'textDocument' => ['uri' => $targetUri, 'version' => null],
                        'edits' => [$this->protocol->textEdit(new Range($position, $position), $newText)],
                    ]]],
                ];
                break;
            }
        }

        return $actions;
    }

    private function target(string $root, string $domain): ?string
    {
        if (1 !== preg_match('/^[A-Za-z0-9_.-]+$/', $domain)) {
            return null;
        }
        $directory = Path::join($root, 'translations');
        if (!is_dir($directory)) {
            return null;
        }

        $target = null;
        $finder = (new Finder())->files()->in($directory)->depth('== 0')->name([$domain.'.*.yaml', $domain.'.*.yml']);
        foreach ($finder as $file) {
            if (null !== $target) {
                return null;
            }
            $target = $file->getPathname();
        }

        return $target;
    }

    private function uri(string $path): string
    {
        return $this->uriToPathConverter->toUri($path);
    }

    /** @param array<array-key, mixed> $range */
    private function sameRange(TranslationReference $reference, array $range): bool
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
