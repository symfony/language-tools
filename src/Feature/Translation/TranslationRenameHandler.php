<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\RenameProviderInterface;
use Symfony\Lsp\Project\Project;

final class TranslationRenameHandler implements RenameProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly TranslationExtractor $extractor,
        private readonly TranslationIndexRegistry $indexes,
    ) {
    }

    public function prepare(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$reference, $project] = $resolved;
        if ([] === $this->indexes->forProject($project)->declarations($reference->domain(), $reference->key())) {
            return null;
        }

        return ['range' => $this->range($reference->range()), 'placeholder' => $reference->key()];
    }

    public function rename(array $params): ?array
    {
        $newName = $params['newName'] ?? null;
        $resolved = $this->resolve($params);
        if (!\is_string($newName) || '' === $newName || str_contains($newName, ' ') || null === $resolved) {
            return null;
        }

        [$reference, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        $declarations = $index->declarations($reference->domain(), $reference->key());
        if ([] === $declarations
            || [] !== $index->declarations($reference->domain(), $newName)
            || [] !== $index->messages($reference->domain(), $newName)
        ) {
            return null;
        }

        $declarationText = $this->declarationText($reference->key(), $newName);
        if (null === $declarationText) {
            return null;
        }

        $byUri = [];
        foreach ($index->references($reference->domain(), $reference->key()) as $item) {
            $byUri[$item->uri()][] = $this->edit($item->range(), $newName);
        }
        foreach ($declarations as $item) {
            $byUri[$item->uri()][] = $this->edit($item->range(), $declarationText);
        }
        ksort($byUri);
        $changes = [];
        foreach ($byUri as $uri => $edits) {
            $changes[] = ['textDocument' => ['uri' => $uri, 'version' => null], 'edits' => $edits];
        }

        return [
            'documentChanges' => $changes,
            'changeAnnotations' => ['translationRename' => [
                'label' => \sprintf('Rename translation "%s" to "%s"', $reference->key(), $newName),
                'needsConfirmation' => true,
                'description' => 'Dynamic translation references may remain unchanged.',
            ]],
        ];
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{TranslationReference, Project}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        $offset = $this->converter->toByteOffset($document->text(), $position);
        $facts = $this->extractor->extract($document->uri(), $document->languageId(), $document->text());
        foreach ($facts->declarations() as $declaration) {
            $start = $this->converter->toByteOffset($document->text(), $declaration->range()->start());
            $end = $this->converter->toByteOffset($document->text(), $declaration->range()->end());
            if ($offset >= $start && $offset <= $end) {
                return [new TranslationReference(
                    $declaration->key(),
                    $declaration->domain(),
                    $document->uri(),
                    $declaration->range(),
                ), $project];
            }
        }
        foreach ($facts->references() as $reference) {
            $start = $this->converter->toByteOffset($document->text(), $reference->range()->start());
            $end = $this->converter->toByteOffset($document->text(), $reference->range()->end());
            if ($offset >= $start && $offset <= $end) {
                return [$reference, $project];
            }
        }

        return null;
    }

    private function declarationText(string $oldName, string $newName): ?string
    {
        $oldSeparator = strrpos($oldName, '.');
        if (false === $oldSeparator) {
            return $newName;
        }
        $newSeparator = strrpos($newName, '.');
        if (false === $newSeparator || substr($oldName, 0, $oldSeparator) !== substr($newName, 0, $newSeparator)) {
            return null;
        }

        return substr($newName, $newSeparator + 1);
    }

    /** @return array<array-key, mixed> */
    private function edit(Range $range, string $newText): array
    {
        return ['range' => $this->range($range), 'newText' => $newText, 'annotationId' => 'translationRename'];
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    private function range(Range $range): array
    {
        return [
            'start' => ['line' => $range->start()->line(), 'character' => $range->start()->character()],
            'end' => ['line' => $range->end()->line(), 'character' => $range->end()->character()],
        ];
    }
}
