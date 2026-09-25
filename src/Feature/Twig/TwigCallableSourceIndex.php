<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Index\AbstractSourceFactsIndex;
use Symfony\Lsp\Index\SourceSymbolOrder;

/** @extends AbstractSourceFactsIndex<TwigCallableSourceFacts> */
final class TwigCallableSourceIndex extends AbstractSourceFactsIndex
{
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

    /** @var array<string, TwigCallableSourceMethod> */
    private array $methods = [];

    /** @return list<string> */
    public function names(TwigCallableKind $kind): array
    {
        $this->derive();

        return $this->names[$kind->value] ?? [];
    }

    /** @return list<TwigCallableUsage> */
    public function usages(TwigCallableKind $kind, string $name): array
    {
        $this->derive();

        return $this->usages[$kind->value][$name] ?? [];
    }

    /** @return list<TwigCallableDeclaration> */
    public function declarations(TwigCallableKind $kind, string $name): array
    {
        $this->derive();

        return $this->declarations[$kind->value][$name] ?? [];
    }

    /** @return list<TwigCallableDeclaration> */
    public function declarationsForCallable(string $className, string $method): array
    {
        $this->derive();

        return $this->declarationsByCallable[TwigCallableKey::from($className, $method)] ?? [];
    }

    public function hasCallableDeclarations(): bool
    {
        $this->derive();

        return [] !== $this->declarationsByCallable;
    }

    public function method(string $className, string $method): ?TwigCallableSourceMethod
    {
        $this->derive();

        return $this->methods[TwigCallableKey::from($className, $method)] ?? null;
    }

    public function declarationAt(string $uri, Position $position): ?TwigCallableDeclaration
    {
        $this->derive();

        return array_find(
            $this->declarationsByUri[$uri] ?? [],
            static fn (TwigCallableDeclaration $declaration): bool => $declaration->range->containsPosition($position),
        );
    }

    protected function build(): void
    {
        $names = [];
        $this->usages = [];
        $this->declarations = [];
        $this->declarationsByCallable = [];
        $this->declarationsByUri = [];
        $this->methods = [];
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
            foreach ($facts->methods as $method) {
                $this->methods[TwigCallableKey::from($method->className, $method->name)] = $method;
            }
        }

        $this->names = [];
        foreach ($names as $kind => $kindNames) {
            $this->names[$kind] = array_keys($kindNames);
            sort($this->names[$kind]);
        }
        $byLocation = SourceSymbolOrder::byLocation(...);
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
                usort($usages, $byLocation);
            }
            unset($usages);
        }
        unset($kindUsages);
    }
}
