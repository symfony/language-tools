<?php

namespace Symfony\Lsp\Feature\Twig;

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
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations() as $declaration) {
                $kind = $declaration->kind()->value;
                $name = $declaration->name();
                $names[$kind][$name] = true;
                $this->declarations[$kind][$name][] = $declaration;
            }
            foreach ($facts->usages() as $usage) {
                $this->usages[$usage->kind()->value][$usage->name()][] = $usage;
            }
        }

        $this->names = [];
        foreach ($names as $kind => $kindNames) {
            $this->names[$kind] = array_keys($kindNames);
            sort($this->names[$kind]);
        }
        foreach ($this->declarations as &$kindDeclarations) {
            foreach ($kindDeclarations as &$declarations) {
                usort($declarations, static fn (TwigCallableDeclaration $left, TwigCallableDeclaration $right): int => [$left->uri(), $left->range()->start()->line(), $left->range()->start()->character()] <=> [$right->uri(), $right->range()->start()->line(), $right->range()->start()->character()]);
            }
            unset($declarations);
        }
        unset($kindDeclarations);
        foreach ($this->usages as &$kindUsages) {
            foreach ($kindUsages as &$usages) {
                usort($usages, static fn (TwigCallableUsage $left, TwigCallableUsage $right): int => [$left->uri(), $left->range()->start()->line(), $left->range()->start()->character()] <=> [$right->uri(), $right->range()->start()->line(), $right->range()->start()->character()]);
            }
            unset($usages);
        }
        unset($kindUsages);
        $this->indexed = true;
    }
}
