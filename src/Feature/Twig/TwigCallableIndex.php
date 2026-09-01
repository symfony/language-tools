<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<TwigCallableSourceFacts> */
final class TwigCallableIndex extends AbstractSourceFactsIndex
{
    private bool $indexed = false;

    /** @var array<string, list<string>> */
    private array $names = [];

    /** @var array<string, array<string, list<TwigCallableUsage>>> */
    private array $usages = [];

    /** @var array<string, array<string, list<TwigCallableDeclaration>>> */
    private array $declarations = [];

    /** @var array<string, list<TwigCallableDeclaration>> */
    private array $declarationsByCallable = [];

    /** @var array<string, list<TwigCallableDeclaration>> */
    private array $declarationsByUri = [];

    /** @return list<string> */
    public function names(TwigCallableKind $kind): array
    {
        $this->index();

        return $this->names[$kind->value] ?? [];
    }

    /** @return list<TwigCallableUsage> */
    public function usages(TwigCallableKind $kind, string $name): array
    {
        $this->index();

        return $this->usages[$kind->value][$name] ?? [];
    }

    /** @return list<TwigCallableDeclaration> */
    public function declarations(TwigCallableKind $kind, string $name): array
    {
        $this->index();

        return $this->declarations[$kind->value][$name] ?? [];
    }

    /** @return list<TwigCallableDeclaration> */
    public function declarationsForCallable(string $className, string $method): array
    {
        $this->index();

        return $this->declarationsByCallable[TwigCallableKey::from($className, $method)] ?? [];
    }

    public function hasCallableDeclarations(): bool
    {
        $this->index();

        return [] !== $this->declarationsByCallable;
    }

    public function declarationAt(string $uri, Position $position): ?TwigCallableDeclaration
    {
        $this->index();

        foreach ($this->declarationsByUri[$uri] ?? [] as $declaration) {
            $start = $declaration->range->start;
            $end = $declaration->range->end;
            $atOrAfterStart = $position->line > $start->line
                || ($position->line === $start->line && $position->character >= $start->character);
            $atOrBeforeEnd = $position->line < $end->line
                || ($position->line === $end->line && $position->character <= $end->character);
            if ($atOrAfterStart && $atOrBeforeEnd) {
                return $declaration;
            }
        }

        return null;
    }

    protected function factsChanged(): void
    {
        $this->indexed = false;
    }

    private function index(): void
    {
        if ($this->indexed) {
            return;
        }

        $names = [];
        $this->usages = [];
        $this->declarations = [];
        $this->declarationsByCallable = [];
        $this->declarationsByUri = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations as $declaration) {
                $kind = $declaration->kind->value;
                $name = $declaration->name;
                $names[$kind][$name] = true;
                $this->declarations[$kind][$name][] = $declaration;
                $this->declarationsByUri[$declaration->uri][] = $declaration;
                if (null !== $declaration->className && null !== $declaration->method) {
                    $this->declarationsByCallable[TwigCallableKey::from($declaration->className, $declaration->method)][] = $declaration;
                }
            }
            foreach ($facts->usages as $usage) {
                $this->usages[$usage->kind->value][$usage->name][] = $usage;
            }
        }

        $this->names = [];
        foreach ($names as $kind => $kindNames) {
            $this->names[$kind] = array_keys($kindNames);
            sort($this->names[$kind]);
        }
        $byLocation = static fn (TwigCallableDeclaration $left, TwigCallableDeclaration $right): int => [$left->uri, $left->range->start->line, $left->range->start->character] <=> [$right->uri, $right->range->start->line, $right->range->start->character];
        foreach ($this->declarations as &$kindDeclarations) {
            foreach ($kindDeclarations as &$declarations) {
                usort($declarations, $byLocation);
            }
            unset($declarations);
        }
        unset($kindDeclarations);
        foreach ($this->declarationsByCallable as &$declarations) {
            usort($declarations, $byLocation);
        }
        unset($declarations);
        foreach ($this->declarationsByUri as &$declarations) {
            usort($declarations, $byLocation);
        }
        unset($declarations);
        foreach ($this->usages as &$kindUsages) {
            foreach ($kindUsages as &$usages) {
                usort($usages, static fn (TwigCallableUsage $left, TwigCallableUsage $right): int => [$left->uri, $left->range->start->line, $left->range->start->character] <=> [$right->uri, $right->range->start->line, $right->range->start->character]);
            }
            unset($usages);
        }
        unset($kindUsages);
        $this->indexed = true;
    }
}
