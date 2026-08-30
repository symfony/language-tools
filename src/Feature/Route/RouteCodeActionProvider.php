<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class RouteCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly RouteIndexRegistry $indexes,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly RouteReferenceExtractor $phpExtractor,
        private readonly TwigRouteReferenceExtractor $twigExtractor,
        private readonly ProjectPathResolver $pathResolver,
    ) {
    }

    public function actions(array $params): ?array
    {
        $request = $this->documentContextResolver->resolveDocument($params);
        $context = $params['context'] ?? null;
        if (null === $request
            || !\is_array($context)
            || !$this->pathResolver->isApplicationOwned($request->project, $request->document->uri)
            || !\in_array($request->document->languageId, ['php', 'twig'], true)
        ) {
            return null;
        }
        $references = 'twig' === $request->document->languageId
            ? $this->twigExtractor->extract($request->document->text)
            : $this->phpExtractor->extract($request->document->text, $this->classIndexes->forProject($request->project));
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
                if (!$this->protocol->sameRange($reference->range(), $range)) {
                    continue;
                }
                $route = $this->indexes->forProject($request->project)->get($reference->name());
                if (null === $route || null === $reference->providedParameters()) {
                    continue;
                }
                $missing = array_values(array_diff($route->requiredParameters(), $reference->providedParameters()));
                $edit = $this->edit($request->document, $reference, $missing);
                if (null === $edit) {
                    continue;
                }
                $actions[] = [
                    'title' => 1 === \count($missing) ? 'Add missing route parameter' : 'Add missing route parameters',
                    'kind' => 'quickfix',
                    'diagnostics' => [$diagnostic],
                    'isPreferred' => true,
                    'edit' => ['documentChanges' => [[
                        'textDocument' => ['uri' => $request->document->uri, 'version' => $request->document->version],
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
        $text = $document->text;
        $end = $this->converter->toByteOffset($text, $reference->range()->end);
        $after = substr($text, $end);
        $twig = 'twig' === $document->languageId;
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

        return $this->protocol->textEdit(new Range($position, $position), $newText);
    }
}
