<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Configuration\ConfigurationOccurrence;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;

final class SecurityExtractor
{
    public function __construct(private readonly PositionConverter $converter, private readonly YamlConfigurationParser $yaml)
    {
    }

    public function extract(string $uri, string $languageId, string $text): SecuritySourceFacts
    {
        $symbols = match ($languageId) {
            'php' => $this->phpSymbols($uri, $text),
            'twig' => $this->twigSymbols($uri, $text),
            'yaml' => $this->yamlSymbols($uri, $text),
            default => [],
        };

        return new SecuritySourceFacts($uri, $this->unique($symbols));
    }

    public function completionContext(string $languageId, string $text, int $offset): ?SecurityCompletionContext
    {
        $before = substr($text, 0, $offset);
        if ('twig' === $languageId && preg_match('/\bis_granted\s*\(\s*["\'](ROLE_[A-Z0-9_]*)$/', $before, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(SecuritySymbolKind::Role, $match[1][0], $text, $match[1][1]);
        }
        if ('php' === $languageId) {
            [$namespace, $imports] = $this->phpNames($text);
            if ($this->hasIsGrantedAttribute($imports) && preg_match('/\bIsGranted\s*\(\s*(?:attribute\s*:\s*)?["\'](ROLE_[A-Z0-9_]*)$/', $before, $match, \PREG_OFFSET_CAPTURE)) {
                return $this->context(SecuritySymbolKind::Role, $match[1][0], $text, $match[1][1]);
            }
            if ($this->extendsAbstractController($text, $namespace, $imports) && preg_match('/\$this\s*->\s*denyAccessUnlessGranted\s*\(\s*["\'](ROLE_[A-Z0-9_]*)$/', $before, $match, \PREG_OFFSET_CAPTURE)) {
                return $this->context(SecuritySymbolKind::Role, $match[1][0], $text, $match[1][1]);
            }
            $authorizationVariables = $this->typedVariables($text, $namespace, $imports, [
                'Symfony\\Bundle\\SecurityBundle\\Security',
                'Symfony\\Component\\Security\\Core\\Authorization\\AuthorizationCheckerInterface',
            ]);
            if (preg_match('/(?:\$([A-Za-z_][A-Za-z0-9_]*)|\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*))\s*->\s*isGranted\s*\(\s*["\'](ROLE_[A-Z0-9_]*)$/', $before, $match, \PREG_OFFSET_CAPTURE)) {
                $variable = '' !== $match[2][0] ? $match[2][0] : $match[1][0];
                if (isset($authorizationVariables[$variable])) {
                    return $this->context(SecuritySymbolKind::Role, $match[3][0], $text, $match[3][1]);
                }
            }
            $logoutVariables = $this->typedVariables($text, $namespace, $imports, ['Symfony\\Component\\Security\\Http\\Logout\\LogoutUrlGenerator']);
            if (preg_match('/(?:\$([A-Za-z_][A-Za-z0-9_]*)|\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*))\s*->\s*getLogout(?:Path|Url)\s*\(\s*["\']([A-Za-z0-9_.-]*)$/', $before, $match, \PREG_OFFSET_CAPTURE)) {
                $variable = '' !== $match[2][0] ? $match[2][0] : $match[1][0];
                if (isset($logoutVariables[$variable])) {
                    return $this->context(SecuritySymbolKind::Firewall, $match[3][0], $text, $match[3][1]);
                }
            }
        }
        if ('twig' === $languageId && preg_match('/\blogout_(?:path|url)\s*\(\s*["\']([A-Za-z0-9_.-]*)$/', $before, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(SecuritySymbolKind::Firewall, $match[1][0], $text, $match[1][1]);
        }
        if ('yaml' === $languageId) {
            $lineOffset = strrpos($before, "\n");
            $lineOffset = false === $lineOffset ? 0 : $lineOffset + 1;
            $line = substr($before, $lineOffset);
            $parent = $this->yamlParentPath(substr($before, 0, $lineOffset));
            if (\count($parent) >= 3 && ['security', 'firewalls'] === \array_slice($parent, 0, 2) && preg_match('/^\s*provider\s*:\s*["\']?([A-Za-z0-9_.-]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
                return $this->context(SecuritySymbolKind::Provider, $match[1][0], $text, $lineOffset + $match[1][1]);
            }
            if ('security' === ($parent[0] ?? null) && \in_array($parent[1] ?? null, ['access_control', 'role_hierarchy'], true) && preg_match('/(?:\broles?\s*:\s*\[?\s*|^\s*ROLE_[A-Z0-9_]+\s*:\s*\[?\s*)["\']?(ROLE_[A-Z0-9_]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
                return $this->context(SecuritySymbolKind::Role, $match[1][0], $text, $lineOffset + $match[1][1]);
            }
        }

        return null;
    }

    /** @return list<SecuritySourceSymbol> */
    private function yamlSymbols(string $uri, string $text): array
    {
        $symbols = [];
        foreach ($this->yaml->parse($text) as $occurrence) {
            $path = $occurrence->path();
            if (3 === \count($path) && 'security' === $path[0] && 'providers' === $path[1]) {
                $symbols[] = new SecuritySourceSymbol(SecuritySymbolKind::Provider, $path[2], $uri, $occurrence->keyRange(), true);
            } elseif (3 === \count($path) && 'security' === $path[0] && 'firewalls' === $path[1]) {
                $symbols[] = new SecuritySourceSymbol(SecuritySymbolKind::Firewall, $path[2], $uri, $occurrence->keyRange(), true);
            } elseif (4 === \count($path) && 'security' === $path[0] && 'firewalls' === $path[1] && 'provider' === $path[3]) {
                array_push($symbols, ...$this->valueSymbols(SecuritySymbolKind::Provider, '/[A-Za-z_][A-Za-z0-9_.-]*/', $uri, $text, $occurrence));
            }
            if (3 === \count($path) && 'security' === $path[0] && 'role_hierarchy' === $path[1] && str_starts_with($path[2], 'ROLE_')) {
                $symbols[] = new SecuritySourceSymbol(SecuritySymbolKind::Role, $path[2], $uri, $occurrence->keyRange(), true);
                array_push($symbols, ...$this->valueSymbols(SecuritySymbolKind::Role, '/ROLE_[A-Z0-9_]+/', $uri, $text, $occurrence));
            } elseif ('security' === ($path[0] ?? null) && 'roles' === ($path[array_key_last($path)] ?? null)) {
                array_push($symbols, ...$this->valueSymbols(SecuritySymbolKind::Role, '/ROLE_[A-Z0-9_]+/', $uri, $text, $occurrence));
            }
        }

        return $symbols;
    }

    /** @return list<SecuritySourceSymbol> */
    private function phpSymbols(string $uri, string $text): array
    {
        $symbols = [];
        [$namespace, $imports] = $this->phpNames($text);
        if ($this->hasIsGrantedAttribute($imports)) {
            preg_match_all('/\bIsGranted\s*\(\s*(?:attribute\s*:\s*)?["\'](ROLE_[A-Z0-9_]+)["\']/', $text, $attributes, \PREG_OFFSET_CAPTURE);
            foreach ($attributes[1] as [$role, $offset]) {
                $symbols[] = $this->symbol(SecuritySymbolKind::Role, $role, $uri, $text, $offset);
            }
        }
        if ($this->extendsAbstractController($text, $namespace, $imports)) {
            preg_match_all('/\$this\s*->\s*denyAccessUnlessGranted\s*\(\s*["\'](ROLE_[A-Z0-9_]+)["\']/', $text, $controllerRoles, \PREG_OFFSET_CAPTURE);
            foreach ($controllerRoles[1] as [$role, $offset]) {
                $symbols[] = $this->symbol(SecuritySymbolKind::Role, $role, $uri, $text, $offset);
            }
        }
        $authorizationVariables = $this->typedVariables($text, $namespace, $imports, [
            'Symfony\\Bundle\\SecurityBundle\\Security',
            'Symfony\\Component\\Security\\Core\\Authorization\\AuthorizationCheckerInterface',
        ]);
        preg_match_all('/(?:\$([A-Za-z_][A-Za-z0-9_]*)|\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*))\s*->\s*isGranted\s*\(\s*["\'](ROLE_[A-Z0-9_]+)["\']/', $text, $roles, \PREG_OFFSET_CAPTURE);
        foreach ($roles[3] as $index => [$role, $offset]) {
            $variable = '' !== $roles[2][$index][0] ? $roles[2][$index][0] : $roles[1][$index][0];
            if (isset($authorizationVariables[$variable])) {
                $symbols[] = $this->symbol(SecuritySymbolKind::Role, $role, $uri, $text, $offset);
            }
        }
        $logoutVariables = $this->typedVariables($text, $namespace, $imports, ['Symfony\\Component\\Security\\Http\\Logout\\LogoutUrlGenerator']);
        preg_match_all('/(?:\$([A-Za-z_][A-Za-z0-9_]*)|\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*))\s*->\s*getLogout(?:Path|Url)\s*\(\s*["\']([A-Za-z0-9_.-]+)["\']/', $text, $firewalls, \PREG_OFFSET_CAPTURE);
        foreach ($firewalls[3] as $index => [$firewall, $offset]) {
            $variable = '' !== $firewalls[2][$index][0] ? $firewalls[2][$index][0] : $firewalls[1][$index][0];
            if (isset($logoutVariables[$variable])) {
                $symbols[] = $this->symbol(SecuritySymbolKind::Firewall, $firewall, $uri, $text, $offset);
            }
        }

        return $symbols;
    }

    /** @return list<SecuritySourceSymbol> */
    private function twigSymbols(string $uri, string $text): array
    {
        $symbols = [];
        preg_match_all('/\bis_granted\s*\(\s*["\'](ROLE_[A-Z0-9_]+)["\']/', $text, $roles, \PREG_OFFSET_CAPTURE);
        foreach ($roles[1] as [$role, $offset]) {
            $symbols[] = $this->symbol(SecuritySymbolKind::Role, $role, $uri, $text, $offset);
        }
        preg_match_all('/\blogout_(?:path|url)\s*\(\s*["\']([A-Za-z0-9_.-]+)["\']/', $text, $firewalls, \PREG_OFFSET_CAPTURE);
        foreach ($firewalls[1] as [$firewall, $offset]) {
            $symbols[] = $this->symbol(SecuritySymbolKind::Firewall, $firewall, $uri, $text, $offset);
        }

        return $symbols;
    }

    /**
     * @return list<SecuritySourceSymbol>
     */
    private function valueSymbols(SecuritySymbolKind $kind, string $pattern, string $uri, string $text, ConfigurationOccurrence $occurrence): array
    {
        $start = $this->converter->toByteOffset($text, $occurrence->valueRange()->start());
        $end = $this->converter->toByteOffset($text, $occurrence->valueRange()->end());
        $value = substr($text, $start, $end - $start);
        preg_match_all($pattern, $value, $matches, \PREG_OFFSET_CAPTURE);
        $symbols = [];
        foreach ($matches[0] as [$name, $offset]) {
            $symbols[] = $this->symbol($kind, $name, $uri, $text, $start + $offset);
        }

        return $symbols;
    }

    private function context(SecuritySymbolKind $kind, string $prefix, string $text, int $offset): SecurityCompletionContext
    {
        return new SecurityCompletionContext($kind, $prefix, new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + \strlen($prefix))));
    }

    private function symbol(SecuritySymbolKind $kind, string $name, string $uri, string $text, int $offset): SecuritySourceSymbol
    {
        return new SecuritySourceSymbol($kind, $name, $uri, new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + \strlen($name))), false);
    }

    /** @return array{string, array<string, string>} */
    private function phpNames(string $text): array
    {
        $namespace = '';
        if (preg_match('/\bnamespace\s+([^;{]+)[;{]/', $text, $match)) {
            $namespace = trim($match[1]);
        }
        $imports = [];
        preg_match_all('/^\s*use\s+([^;]+);/m', $text, $matches);
        foreach ($matches[1] as $import) {
            if (str_contains($import, '{')) {
                continue;
            }
            $parts = preg_split('/\s+as\s+/i', trim($import));
            if (false === $parts || [] === $parts) {
                continue;
            }
            $className = ltrim($parts[0], '\\');
            $alias = $parts[1] ?? substr($className, (int) strrpos('\\'.$className, '\\'));
            $imports[$alias] = $className;
        }

        return [$namespace, $imports];
    }

    /** @param array<string, string> $imports */
    private function hasIsGrantedAttribute(array $imports): bool
    {
        return 'Symfony\\Component\\Security\\Http\\Attribute\\IsGranted' === ($imports['IsGranted'] ?? null);
    }

    /**
     * @param array<string, string> $imports
     * @param list<string>          $acceptedTypes
     *
     * @return array<string, true>
     */
    private function typedVariables(string $text, string $namespace, array $imports, array $acceptedTypes): array
    {
        $variables = [];
        preg_match_all('/(\\??[\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (\in_array($this->resolvePhpName(ltrim($match[1], '?'), $namespace, $imports), $acceptedTypes, true)) {
                $variables[$match[2]] = true;
            }
        }

        return $variables;
    }

    /** @param array<string, string> $imports */
    private function extendsAbstractController(string $text, string $namespace, array $imports): bool
    {
        return preg_match('/\bclass\s+[A-Za-z_][A-Za-z0-9_]*\s+extends\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)/', $text, $match)
            && 'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController' === $this->resolvePhpName($match[1], $namespace, $imports);
    }

    /** @param array<string, string> $imports */
    private function resolvePhpName(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $separator = strpos($name, '\\');
        $head = false === $separator ? $name : substr($name, 0, $separator);
        if (isset($imports[$head])) {
            return $imports[$head].(false === $separator ? '' : substr($name, $separator));
        }

        return '' === $namespace ? $name : $namespace.'\\'.$name;
    }

    /** @return list<string> */
    private function yamlParentPath(string $text): array
    {
        $stack = [];
        preg_match_all('/^.*(?:\R|$)/m', $text, $lines);
        foreach ($lines[0] as $line) {
            $line = rtrim($line, "\r\n");
            if (!preg_match('/^(\s*)(?:-\s+)?([A-Za-z_][A-Za-z0-9_.@-]*)\s*:\s*(.*)$/', $line, $match)) {
                continue;
            }
            $indent = \strlen($match[1]);
            foreach (array_keys($stack) as $level) {
                if ($level >= $indent) {
                    unset($stack[$level]);
                }
            }
            $parent = [];
            ksort($stack);
            foreach ($stack as $path) {
                $parent = $path;
            }
            if ('' === trim($match[3])) {
                $stack[$indent] = [...$parent, str_replace('-', '_', $match[2])];
            }
        }
        $parent = [];
        ksort($stack);
        foreach ($stack as $path) {
            $parent = $path;
        }

        return $parent;
    }

    /**
     * @param list<SecuritySourceSymbol> $symbols
     *
     * @return list<SecuritySourceSymbol>
     */
    private function unique(array $symbols): array
    {
        $unique = [];
        foreach ($symbols as $symbol) {
            $key = $symbol->kind()->value.'|'.$symbol->range()->start()->line().'|'.$symbol->range()->start()->character();
            $unique[$key] = $symbol;
        }

        return array_values($unique);
    }
}
