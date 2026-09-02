<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Index\SourceDocument;
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
        $document = SourceDocument::fromDocument($request->document);
        $references = 'twig' === $request->document->languageId
            ? $this->twigExtractor->extract($document)
            : $this->phpExtractor->extract($document, $this->classIndexes->forProject($request->project));
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
                if (!$this->protocol->sameRange($reference->range, $range)) {
                    continue;
                }
                $routeIndex = $this->indexes->forProject($request->project);
                $route = $routeIndex->get($reference->name);
                if (null === $route || null === $reference->providedParameters) {
                    continue;
                }
                $missing = $routeIndex->missingParameters($route, $reference->providedParameters);
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
        $start = $this->converter->toByteOffset($text, $reference->range->start);
        $end = $this->converter->toByteOffset($text, $reference->range->end);
        $after = substr($text, $end);
        $twig = 'twig' === $document->languageId;
        $separator = $twig ? ': ' : ' => ';
        $entries = implode(', ', array_map(
            static fn (string $name): string => "'".str_replace("'", "\\'", $name)."'".$separator.'null',
            $missing,
        ));
        $namedSeparator = null;
        $parametersOffset = null;
        $before = substr($text, 0, $start);
        if ($twig && preg_match('/\bname(\s*[:=]\s*)[\'\"]$/', $before, $namedMatch, \PREG_OFFSET_CAPTURE)) {
            $namedSeparator = str_contains($namedMatch[1][0], '=') ? ' = ' : ': ';
            $parametersOffset = $this->namedParametersOffset($before, $namedMatch[0][1]);
        }
        if (null !== $parametersOffset) {
            $offset = $parametersOffset;
            $newText = $entries.', ';
        } elseif (null !== $namedSeparator && preg_match('/^([\'\"])\s*,\s*parameters\s*[:=]\s*\{/', $after, $match, \PREG_OFFSET_CAPTURE)) {
            $offset = $end + \strlen($match[0][0]);
            $newText = $entries.', ';
        } elseif (preg_match('/^([\'\"])\s*\)/', $after, $match, \PREG_OFFSET_CAPTURE)) {
            $offset = $end + \strlen($match[1][0]);
            $newText = ', '.(null !== $namedSeparator ? 'parameters'.$namedSeparator : '').($twig ? '{'.$entries.'}' : '['.$entries.']');
        } elseif (preg_match('/^([\'\"])\s*,\s*([\[\{])/', $after, $match, \PREG_OFFSET_CAPTURE)) {
            $offset = $end + $match[2][1] + 1;
            $newText = $entries.', ';
        } else {
            return null;
        }
        $position = $this->converter->toPosition($text, $offset);

        return $this->protocol->textEdit(new Range($position, $position), $newText);
    }

    private function namedParametersOffset(string $text, int $nameOffset): ?int
    {
        preg_match_all('/\bparameters\s*[:=]\s*\{/', substr($text, 0, $nameOffset), $matches, \PREG_OFFSET_CAPTURE);
        foreach (array_reverse($matches[0]) as [$match, $offset]) {
            $openingOffset = $offset + \strlen($match) - 1;
            $closingOffset = $this->closingDelimiterOffset($text, $openingOffset);
            if (null !== $closingOffset && 1 === preg_match('/^\s*,\s*$/', substr($text, $closingOffset + 1, $nameOffset - $closingOffset - 1))) {
                return $openingOffset + 1;
            }
        }

        return null;
    }

    private function closingDelimiterOffset(string $text, int $openingOffset): ?int
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $closing = $pairs[$text[$openingOffset]] ?? null;
        if (null === $closing) {
            return null;
        }
        $stack = [$closing];
        $quote = null;
        $escaped = false;
        for ($offset = $openingOffset + 1, $length = \strlen($text); $offset < $length; ++$offset) {
            $character = $text[$offset];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }

                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
            } elseif (isset($pairs[$character])) {
                $stack[] = $pairs[$character];
            } elseif ($character === $stack[array_key_last($stack)]) {
                array_pop($stack);
                if ([] === $stack) {
                    return $offset;
                }
            }
        }

        return null;
    }
}
