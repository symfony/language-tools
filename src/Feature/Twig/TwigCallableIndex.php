<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<TwigCallableSourceFacts> */
final class TwigCallableIndex extends AbstractSourceFactsIndex
{
    /** @return list<string> */
    public function names(TwigCallableKind $kind): array
    {
        $names = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations() as $declaration) {
                if ($kind === $declaration->kind()) {
                    $names[$declaration->name()] = true;
                }
            }
        }
        ksort($names);

        return array_keys($names);
    }

    /** @return list<TwigCallableUsage> */
    public function usages(TwigCallableKind $kind, string $name): array
    {
        $usages = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->usages() as $usage) {
                if ($kind === $usage->kind() && $name === $usage->name()) {
                    $usages[] = $usage;
                }
            }
        }
        usort($usages, static fn (TwigCallableUsage $left, TwigCallableUsage $right): int => [$left->uri(), $left->range()->start()->line(), $left->range()->start()->character()] <=> [$right->uri(), $right->range()->start()->line(), $right->range()->start()->character()]);

        return $usages;
    }

    /** @return list<TwigCallableDeclaration> */
    public function declarations(TwigCallableKind $kind, string $name): array
    {
        $declarations = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations() as $declaration) {
                if ($kind === $declaration->kind() && $name === $declaration->name()) {
                    $declarations[] = $declaration;
                }
            }
        }
        usort($declarations, static fn (TwigCallableDeclaration $left, TwigCallableDeclaration $right): int => [$left->uri(), $left->range()->start()->line(), $left->range()->start()->character()] <=> [$right->uri(), $right->range()->start()->line(), $right->range()->start()->character()]);

        return $declarations;
    }
}
