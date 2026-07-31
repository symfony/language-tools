<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Project\ProjectRegistry;

final class RouteDocumentLinkHandler implements DocumentLinkProviderInterface
{
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly RouteDeclarationIndexRegistry $declarationIndexes,
        private readonly RouteReferenceExtractor $phpReferenceExtractor,
        private readonly TwigRouteReferenceExtractor $twigReferenceExtractor,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, target: string, tooltip: string}>|null
     */
    public function links(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }

        $document = $this->documents->get($textDocument['uri']);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $document || null === $project || !\in_array($document->languageId(), ['php', 'twig'], true)) {
            return null;
        }

        $references = 'twig' === $document->languageId()
            ? $this->twigReferenceExtractor->extract($document->text())
            : $this->phpReferenceExtractor->extract($document->text());
        $links = [];
        foreach ($references as $reference) {
            $declarations = $this->declarationIndexes->forProject($project)->find($reference->name());
            if (1 !== \count($declarations)) {
                continue;
            }

            $declaration = $declarations[0];
            $links[] = [
                'range' => [
                    'start' => [
                        'line' => $reference->range()->start()->line(),
                        'character' => $reference->range()->start()->character(),
                    ],
                    'end' => [
                        'line' => $reference->range()->end()->line(),
                        'character' => $reference->range()->end()->character(),
                    ],
                ],
                'target' => $declaration->uri().'#L'.($declaration->range()->start()->line() + 1),
                'tooltip' => \sprintf('Open route "%s"', $reference->name()),
            ];
        }

        return $links;
    }
}
