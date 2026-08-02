<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Project\ProjectRegistry;

final class RouteCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly PositionConverter $converter,
        private readonly RouteIndexRegistry $indexes,
        private readonly RouteReferenceExtractor $phpExtractor,
        private readonly TwigRouteReferenceExtractor $twigExtractor,
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
        if (null === $document || null === $project || !\in_array($document->languageId(), ['php', 'twig'], true)) {
            return null;
        }
        $references = 'twig' === $document->languageId()
            ? $this->twigExtractor->extract($document->text())
            : $this->phpExtractor->extract($document->text());
        $actions = [];
        foreach (\is_array($context['diagnostics'] ?? null) ? $context['diagnostics'] : [] as $diagnostic) {
            if (!\is_array($diagnostic) || 'route.missing_parameters' !== ($diagnostic['code'] ?? null)) {
                continue;
            }
            $range = $diagnostic['range'] ?? null;
            if (!\is_array($range)) {
                continue;
            }
            foreach ($references as $reference) {
                if (!$this->sameRange($reference, $range)) {
                    continue;
                }
                $route = $this->indexes->forProject($project)->get($reference->name());
                if (null === $route || null === $reference->providedParameters()) {
                    continue;
                }
                $missing = array_values(array_diff($route->requiredParameters(), $reference->providedParameters()));
                $edit = $this->edit($document, $reference, $missing);
                if (null === $edit) {
                    continue;
                }
                $actions[] = [
                    'title' => 1 === \count($missing) ? 'Add missing route parameter' : 'Add missing route parameters',
                    'kind' => 'quickfix',
                    'diagnostics' => [$diagnostic],
                    'isPreferred' => true,
                    'edit' => ['documentChanges' => [[
                        'textDocument' => ['uri' => $document->uri(), 'version' => $document->version()],
                        'edits' => [$edit],
                    ]]],
                ];
                break;
            }
        }

        return $actions;
    }

    /**
     * @param list<string> $missing
     *
     * @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string}|null
     */
    private function edit(Document $document, RouteReference $reference, array $missing): ?array
    {
        if ([] === $missing) {
            return null;
        }
        $text = $document->text();
        $end = $this->converter->toByteOffset($text, $reference->range()->end());
        $after = substr($text, $end);
        $twig = 'twig' === $document->languageId();
        $separator = $twig ? ': ' : ' => ';
        $entries = implode(', ', array_map(
            static fn (string $name): string => "'".str_replace("'", "\\'", $name)."'".$separator.'null',
            $missing,
        ));
        if (preg_match('/^([\'\"])\s*\)/', $after, $match, \PREG_OFFSET_CAPTURE)) {
            $offset = $end + \strlen($match[1][0]);
            $newText = ', '.($twig ? '{'.$entries.'}' : '['.$entries.']');
        } elseif (preg_match('/^([\'\"])\s*,\s*([\[\{])/', $after, $match, \PREG_OFFSET_CAPTURE)) {
            $offset = $end + $match[2][1] + 1;
            $newText = $entries.', ';
        } else {
            return null;
        }
        $position = $this->converter->toPosition($text, $offset);

        return [
            'range' => [
                'start' => ['line' => $position->line(), 'character' => $position->character()],
                'end' => ['line' => $position->line(), 'character' => $position->character()],
            ],
            'newText' => $newText,
        ];
    }

    /** @param array<array-key, mixed> $range */
    private function sameRange(RouteReference $reference, array $range): bool
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
