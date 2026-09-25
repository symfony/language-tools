<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Index\AbstractSourceFactsIndex;
use Symfony\Lsp\Index\SourceSymbolOrder;

/** @extends AbstractSourceFactsIndex<TwigPhpSymbolSourceFacts> */
final class TwigPhpSymbolIndex extends AbstractSourceFactsIndex
{
    /** @var array<string, list<TwigPhpSymbolDeclaration>> */
    private array $types = [];

    /** @var array<string, array<string, list<TwigPhpSymbolDeclaration>>> */
    private array $members = [];

    /** @var array<string, list<TwigPhpSymbolReference>> */
    private array $references = [];

    /** @var array<string, list<TwigPhpSymbolDeclaration>> */
    private array $declarationsByUri = [];

    /** @var list<string> */
    private array $enumNames = [];

    /** @var list<string> */
    private array $constantTypeNames = [];

    /** @return list<TwigPhpSymbolDeclaration> */
    public function typeDeclarations(string $className): array
    {
        $this->derive();

        return $this->types[$this->classKey($className)] ?? [];
    }

    /** @return list<TwigPhpSymbolDeclaration> */
    public function memberDeclarations(string $className, string $memberName): array
    {
        $this->derive();

        return $this->members[$this->classKey($className)][$memberName] ?? [];
    }

    /** @return list<TwigPhpSymbolReference> */
    public function references(string $className, ?string $memberName): array
    {
        $this->derive();

        return $this->references[$this->referenceKey($className, $memberName)] ?? [];
    }

    /** @return list<string> */
    public function enumNames(): array
    {
        $this->derive();

        return $this->enumNames;
    }

    /** @return list<string> */
    public function constantTypeNames(): array
    {
        $this->derive();

        return $this->constantTypeNames;
    }

    /** @return list<TwigPhpSymbolDeclaration> */
    public function completableMembers(string $className, bool $enumCasesOnly): array
    {
        $this->derive();
        $members = [];
        foreach ($this->members[$this->classKey($className)] ?? [] as $name => $declarations) {
            foreach ($declarations as $declaration) {
                if (!$declaration->public || ($enumCasesOnly && TwigPhpSymbolKind::EnumCase !== $declaration->kind)) {
                    continue;
                }
                $members[$name] = $declaration;
                break;
            }
        }
        ksort($members);

        return array_values($members);
    }

    public function declarationAt(string $uri, Position $position): ?TwigPhpSymbolDeclaration
    {
        $this->derive();

        return array_find(
            $this->declarationsByUri[$uri] ?? [],
            static fn (TwigPhpSymbolDeclaration $declaration): bool => $declaration->range->containsPosition($position),
        );
    }

    protected function build(): void
    {
        $this->types = [];
        $this->members = [];
        $this->references = [];
        $this->declarationsByUri = [];
        $enumNames = [];
        $constantTypeNames = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations as $declaration) {
                $classKey = $this->classKey($declaration->className);
                $this->declarationsByUri[$declaration->uri][] = $declaration;
                if ($declaration->kind->isType()) {
                    $this->types[$classKey][] = $declaration;
                    if (TwigPhpSymbolKind::Enum === $declaration->kind) {
                        $enumNames[$declaration->className] = true;
                    }

                    continue;
                }
                $memberName = $declaration->memberName;
                if (null === $memberName) {
                    continue;
                }
                $this->members[$classKey][$memberName][] = $declaration;
                if ($declaration->public) {
                    $constantTypeNames[$declaration->className] = true;
                }
            }
            foreach ($facts->references as $reference) {
                $this->references[$this->referenceKey($reference->className, $reference->memberName)][] = $reference;
            }
        }

        $this->enumNames = array_keys($enumNames);
        sort($this->enumNames);
        $this->constantTypeNames = array_keys($constantTypeNames);
        sort($this->constantTypeNames);
        $byLocation = SourceSymbolOrder::byLocation(...);
        foreach ($this->types as &$declarations) {
            usort($declarations, $byLocation);
        }
        unset($declarations);
        foreach ($this->members as &$classMembers) {
            foreach ($classMembers as &$declarations) {
                usort($declarations, $byLocation);
            }
            unset($declarations);
        }
        unset($classMembers);
        foreach ($this->declarationsByUri as &$declarations) {
            usort($declarations, $byLocation);
        }
        unset($declarations);
        foreach ($this->references as &$references) {
            usort($references, $byLocation);
        }
        unset($references);
    }

    private function classKey(string $className): string
    {
        return strtolower(ltrim($className, '\\'));
    }

    private function referenceKey(string $className, ?string $memberName): string
    {
        return $this->classKey($className)."\0".$memberName;
    }
}
