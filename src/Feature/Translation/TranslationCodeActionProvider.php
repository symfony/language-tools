<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\ProjectDocumentReader;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\CodeActionRequest;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TranslationCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TranslationExtractor $extractor,
        private readonly TranslationIndexRegistry $indexes,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly ProjectPathResolver $pathResolver,
        private readonly ProjectDocumentReader $reader,
        private readonly UnknownNameCodeActionBuilder $unknownNames,
    ) {
    }

    public function actions(CodeActionRequest $request): array
    {
        if ([] === $request->diagnostics('translation.not_found')
            || !$this->pathResolver->isApplicationOwned($request->project, $request->document->uri)
        ) {
            return [];
        }

        $references = $this->extractor->extract($request->source)->references;
        $index = $this->indexes->forProject($request->project);
        $actions = [];
        foreach ($request->diagnostics('translation.not_found') as $diagnostic) {
            foreach ($references as $reference) {
                if (!$reference->range->equals($diagnostic->range)
                    || [] !== $index->declarations($reference->domain, $reference->key)
                    || [] !== $index->messages($reference->domain, $reference->key)
                ) {
                    continue;
                }
                $replacements = $index->isComplete() ? $this->unknownNames->replacements(
                    $request->document,
                    $diagnostic->diagnostic,
                    $reference->range,
                    $reference->key,
                    $index->keys($reference->domain, ''),
                ) : [];
                array_push($actions, ...$replacements);
                $targetPath = $this->target($request->project->rootPath, $reference->domain);
                if (null === $targetPath) {
                    break;
                }
                $targetUri = $this->uri($targetPath);
                $target = $this->reader->read($request->project, $targetUri);
                if (null === $target) {
                    break;
                }
                $position = $this->converter->toPosition($target->text, \strlen($target->text));
                $escapedKey = str_replace("'", "''", $reference->key);
                $newText = ('' === $target->text || str_ends_with($target->text, "\n") ? '' : "\n")."'{$escapedKey}': '{$escapedKey}'\n";
                $actions[] = $this->protocol->quickFix(
                    \sprintf('Add translation "%s" to %s', $reference->key, basename($targetPath)),
                    $diagnostic->diagnostic,
                    [$this->protocol->textDocumentEdit($targetUri, $target->version, [$this->protocol->textEdit(new Range($position, $position), $newText)])],
                    [] === $replacements,
                );
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
}
