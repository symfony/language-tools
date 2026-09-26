<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\CodeActionRequest;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class RouteCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly RouteIndexRegistry $indexes,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly RouteReferenceExtractor $phpExtractor,
        private readonly TwigRouteReferenceExtractor $twigExtractor,
        private readonly ProjectPathResolver $pathResolver,
        private readonly UnknownNameCodeActionBuilder $unknownNames,
    ) {
    }

    public function actions(CodeActionRequest $request): array
    {
        if (!$this->pathResolver->isApplicationOwned($request->project, $request->document->uri)
            || !\in_array($request->document->languageId, ['php', 'twig'], true)
            || [] === $request->diagnostics('route.not_found', 'route.missing_parameters')
        ) {
            return [];
        }
        $references = 'twig' === $request->document->languageId
            ? $this->twigExtractor->extract($request->source)
            : $this->phpExtractor->extract($request->source, $this->classIndexes->forProject($request->project));
        $routeIndex = $this->indexes->forProject($request->project);
        $actions = $this->unknownNames->actions(
            $request,
            ['route.not_found'],
            $references,
            static fn (RouteReference $reference): ?array => $routeIndex->isComplete() && null === $routeIndex->get($reference->name)
                ? [$reference->name, array_map(static fn (Route $route): string => $route->name, $routeIndex->matching(''))]
                : null,
        );
        foreach ($request->diagnostics('route.missing_parameters') as $diagnostic) {
            foreach ($references as $reference) {
                if (!$reference->range->equals($diagnostic->range)) {
                    continue;
                }
                $route = $routeIndex->get($reference->name);
                if (null === $route || null === $reference->providedParameters) {
                    continue;
                }
                $missing = $routeIndex->missingParameters($route, $reference->providedParameters);
                $edit = $this->edit($request->document, $reference, $missing);
                if (null === $edit) {
                    continue;
                }
                $actions[] = $this->protocol->quickFix(
                    1 === \count($missing) ? 'Add missing route parameter' : 'Add missing route parameters',
                    $diagnostic->diagnostic,
                    [$this->protocol->textDocumentEdit($request->document->uri, $request->document->version, [$edit])],
                    true,
                );
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
