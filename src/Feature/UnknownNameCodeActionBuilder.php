<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\RangedSourceSymbolInterface;
use Symfony\Lsp\Protocol\CodeActionRequest;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class UnknownNameCodeActionBuilder
{
    public function __construct(private readonly LspProtocolMapper $protocol)
    {
    }

    /**
     * Suggests close existing names for the symbol an unknown name diagnostic
     * reports, taken from the symbols of the document at the same range.
     *
     * @template TSymbol of RangedSourceSymbolInterface
     *
     * @param list<string>                                                $codes
     * @param iterable<TSymbol>                                           $symbols
     * @param callable(TSymbol, string): ?array{string, iterable<string>} $replaceable the name to replace and the names to suggest, or null when the diagnostic does not report that symbol
     *
     * @return list<array<array-key, mixed>>
     */
    public function actions(CodeActionRequest $request, array $codes, iterable $symbols, callable $replaceable): array
    {
        $actions = [];
        foreach ($request->diagnostics(...$codes) as $diagnostic) {
            foreach ($symbols as $symbol) {
                if (!$symbol->range->equals($diagnostic->range)) {
                    continue;
                }
                $replacement = $replaceable($symbol, $diagnostic->code);
                if (null !== $replacement) {
                    array_push($actions, ...$this->replacements($request->document, $diagnostic->diagnostic, $symbol->range, $replacement[0], $replacement[1]));
                }

                break;
            }
        }

        return $actions;
    }

    /**
     * @param array<array-key, mixed> $diagnostic
     * @param iterable<string>        $candidates
     * @param list<Range>             $additionalRanges
     *
     * @return list<array<array-key, mixed>>
     */
    public function replacements(Document $document, array $diagnostic, Range $range, string $name, iterable $candidates, array $additionalRanges = []): array
    {
        if ('' === $name || \strlen($name) > 255) {
            return [];
        }

        $threshold = \strlen($name) < 8 ? 1 : 2;
        $matches = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if ($candidate === $name || isset($seen[$candidate]) || abs(\strlen($candidate) - \strlen($name)) > $threshold) {
                continue;
            }
            $distance = levenshtein(strtolower($name), strtolower($candidate));
            if ($distance <= $threshold) {
                $matches[] = ['name' => $candidate, 'distance' => $distance];
                $seen[$candidate] = true;
            }
        }
        usort($matches, static fn (array $a, array $b): int => ($a['distance'] <=> $b['distance']) ?: strcmp($a['name'], $b['name']));
        $preferred = isset($matches[0]) && $matches[0]['distance'] <= 1 && (!isset($matches[1]) || $matches[1]['distance'] > $matches[0]['distance']);

        $actions = [];
        foreach (\array_slice($matches, 0, 3) as $match) {
            $candidate = $match['name'];
            $edits = [$this->protocol->textEdit($range, $candidate)];
            foreach ($additionalRanges as $additionalRange) {
                $edits[] = $this->protocol->textEdit($additionalRange, $candidate);
            }
            $actions[] = $this->protocol->quickFix(
                \sprintf('Replace with "%s"', $candidate),
                $diagnostic,
                [$this->protocol->textDocumentEdit($document->uri, $document->version, $edits)],
                $preferred && [] === $actions,
            );
        }

        return $actions;
    }
}
