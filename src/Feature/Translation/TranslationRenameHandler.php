<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Feature\RenameEditBuilder;
use Symfony\Lsp\Feature\RenameProviderInterface;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\RenameRequest;

final class TranslationRenameHandler implements RenameProviderInterface
{
    public function __construct(
        private readonly TranslationReferenceResolver $referenceResolver,
        private readonly LspProtocolMapper $protocol,
        private readonly TranslationIndexRegistry $indexes,
        private readonly ProjectPathResolver $pathResolver,
        private readonly RenameEditBuilder $editBuilder,
    ) {
    }

    public function prepare(PositionedRequest $request): ?array
    {
        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return null;
        }
        $reference = $resolved->reference;
        $project = $resolved->project;
        $declarations = $this->indexes->forProject($project)->declarations($reference->domain, $reference->key);
        if ([] === array_filter(
            $declarations,
            fn (TranslationDeclaration $declaration): bool => $this->pathResolver->isApplicationOwned($project, $declaration->uri),
        )) {
            return null;
        }

        return ['range' => $this->protocol->range($reference->range), 'placeholder' => $reference->key];
    }

    public function rename(RenameRequest $request): ?array
    {
        $newName = $request->newName;
        $resolved = $this->resolve($request);
        if (!$this->isLiteralSafe($newName) || null === $resolved) {
            return null;
        }

        $reference = $resolved->reference;
        $project = $resolved->project;
        $index = $this->indexes->forProject($project);
        $declarations = $index->declarations($reference->domain, $reference->key);
        if ([] === array_filter(
            $declarations,
            fn (TranslationDeclaration $declaration): bool => $this->pathResolver->isApplicationOwned($project, $declaration->uri),
        )
            || [] !== $index->declarations($reference->domain, $newName)
            || [] !== $index->messages($reference->domain, $newName)
        ) {
            return null;
        }

        $declarationText = $this->declarationText($reference->key, $newName);
        if (null === $declarationText) {
            return null;
        }

        $locations = [];
        foreach ($index->references($reference->domain, $reference->key) as $item) {
            if ($this->pathResolver->isApplicationOwned($project, $item->uri)) {
                $locations[] = [$item->uri, $item->range, $newName];
            }
        }
        foreach ($declarations as $item) {
            if ($this->pathResolver->isApplicationOwned($project, $item->uri)) {
                $locations[] = [$item->uri, $item->range, $declarationText];
            }
        }

        return [
            'documentChanges' => $this->editBuilder->documentChanges($locations, 'translationRename'),
            'changeAnnotations' => ['translationRename' => [
                'label' => \sprintf('Rename translation "%s" to "%s"', $reference->key, $newName),
                'needsConfirmation' => true,
                'description' => 'Dynamic translation references may remain unchanged.',
            ]],
        ];
    }

    private function resolve(PositionedRequest $request): ?ResolvedTranslationReference
    {
        $resolved = $this->referenceResolver->resolve($request);
        if (null === $resolved || !$this->pathResolver->isApplicationOwned($resolved->project, $resolved->reference->uri)) {
            return null;
        }

        return $resolved;
    }

    private function isLiteralSafe(string $name): bool
    {
        return 1 === preg_match('/^[^\s\'"\\\\<>&$#{}\[\]!*%@`|,?-][^\s\'"\\\\<>&$#{}\[\]]*$/u', $name);
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
}
