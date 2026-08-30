<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Configuration\ConfigurationOccurrence;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpTypeDeclaration;
use Symfony\Lsp\Parser\Php\PhpTypedVariableKind;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;

final class SecurityExtractor
{
    private const ABSTRACT_CONTROLLER = 'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController';
    private const AUTHORIZATION_TYPES = [
        'Symfony\\Bundle\\SecurityBundle\\Security',
        'Symfony\\Component\\Security\\Core\\Authorization\\AuthorizationCheckerInterface',
    ];
    private const IS_GRANTED_ATTRIBUTE = 'Symfony\\Component\\Security\\Http\\Attribute\\IsGranted';
    private const LOGOUT_URL_GENERATOR = 'Symfony\\Component\\Security\\Http\\Logout\\LogoutUrlGenerator';

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly YamlConfigurationParser $yaml,
        private readonly TwigCommentParser $commentParser,
        private readonly PhpParserInterface $phpParser,
        private readonly PhpCommentParserInterface $phpComments,
    ) {
    }

    public function extract(string $uri, string $languageId, string $text): SecuritySourceFacts
    {
        $symbols = match ($languageId) {
            'php' => $this->phpSymbols($uri, $text),
            'twig' => $this->twigSymbols($uri, $this->commentParser->mask($text)),
            'yaml' => $this->yamlSymbols($uri, $text),
            default => [],
        };

        return new SecuritySourceFacts($uri, $this->unique($symbols));
    }

    public function completionContext(string $languageId, string $text, int $offset): ?SecurityCompletionContext
    {
        $before = substr(match ($languageId) {
            'twig' => $this->commentParser->mask($text),
            'php' => $this->phpComments->mask($text),
            default => $text,
        }, 0, $offset);
        if ('twig' === $languageId && preg_match('/\bis_granted\s*\(\s*["\'](ROLE_[A-Z0-9_]*)$/', $before, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(SecuritySymbolKind::Role, $match[1][0], $text, $match[1][1]);
        }
        if ('php' === $languageId) {
            $php = $this->phpParser->parse($text);
            if (preg_match('/#\[\s*([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*\(\s*(?:attribute\s*:\s*)?["\'](ROLE_[A-Z0-9_]*)$/', $before, $match, \PREG_OFFSET_CAPTURE)
                && self::IS_GRANTED_ATTRIBUTE === $php->resolveName($match[1][0])
            ) {
                return $this->context(SecuritySymbolKind::Role, $match[2][0], $text, $match[2][1]);
            }
            if (preg_match('/\$this\s*->\s*(denyAccessUnlessGranted)\s*\(\s*["\'](ROLE_[A-Z0-9_]*)$/', $before, $match, \PREG_OFFSET_CAPTURE)
                && $this->isAbstractControllerAt($php, $match[1][1])
            ) {
                return $this->context(SecuritySymbolKind::Role, $match[2][0], $text, $match[2][1]);
            }
            if (preg_match('/(?:\$([A-Za-z_][A-Za-z0-9_]*)|\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*))\s*->\s*(isGranted)\s*\(\s*["\'](ROLE_[A-Z0-9_]*)$/', $before, $match, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL)) {
                $property = \is_string($match[2][0] ?? null);
                $receiver = $property ? $match[2][0] : ($match[1][0] ?? null);
                $prefix = $match[4][0];
                if (\is_string($receiver) && \is_string($prefix) && $this->hasTypedVariableAt($php, self::AUTHORIZATION_TYPES, $match[3][1], $receiver, $property)) {
                    return $this->context(SecuritySymbolKind::Role, $prefix, $text, $match[4][1]);
                }
            }
            if (preg_match('/(?:\$([A-Za-z_][A-Za-z0-9_]*)|\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*))\s*->\s*(getLogout(?:Path|Url))\s*\(\s*["\']([A-Za-z0-9_.-]*)$/', $before, $match, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL)) {
                $property = \is_string($match[2][0] ?? null);
                $receiver = $property ? $match[2][0] : ($match[1][0] ?? null);
                $prefix = $match[4][0];
                if (\is_string($receiver) && \is_string($prefix) && $this->hasTypedVariableAt($php, [self::LOGOUT_URL_GENERATOR], $match[3][1], $receiver, $property)) {
                    return $this->context(SecuritySymbolKind::Firewall, $prefix, $text, $match[4][1]);
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
            $parent = $this->yaml->parentPath($text, $lineOffset);
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
        $php = $this->phpParser->parse($text);
        foreach ($php->attributes as $attribute) {
            if (self::IS_GRANTED_ATTRIBUTE !== $attribute->name) {
                continue;
            }
            $role = ($attribute->argument('attribute') ?? $attribute->argument(0))?->stringLiteral;
            if (null !== $role && preg_match('/^ROLE_[A-Z0-9_]+$/D', $role->value)) {
                $symbols[] = $this->symbol(SecuritySymbolKind::Role, $role->value, $uri, $text, $role->startOffset);
            }
        }
        foreach ($php->methodCalls as $call) {
            $argument = $call->argument(0)?->stringLiteral;
            if (null === $argument) {
                continue;
            }
            if ('isGranted' === $call->method
                && preg_match('/^ROLE_[A-Z0-9_]+$/D', $argument->value)
                && $this->hasTypedReceiver($call, $php, self::AUTHORIZATION_TYPES)
            ) {
                $symbols[] = $this->symbol(SecuritySymbolKind::Role, $argument->value, $uri, $text, $argument->startOffset);
            } elseif ('denyAccessUnlessGranted' === $call->method
                && preg_match('/^ROLE_[A-Z0-9_]+$/D', $argument->value)
                && PhpMethodReceiverKind::This === $call->receiverContext->kind
                && $this->extendsAbstractController($php, $call->className)
            ) {
                $symbols[] = $this->symbol(SecuritySymbolKind::Role, $argument->value, $uri, $text, $argument->startOffset);
            } elseif (\in_array($call->method, ['getLogoutPath', 'getLogoutUrl'], true)
                && preg_match('/^[A-Za-z0-9_.-]+$/D', $argument->value)
                && $this->hasTypedReceiver($call, $php, [self::LOGOUT_URL_GENERATOR])
            ) {
                $symbols[] = $this->symbol(SecuritySymbolKind::Firewall, $argument->value, $uri, $text, $argument->startOffset);
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
        $start = $this->converter->toByteOffset($text, $occurrence->valueRange()->start);
        $end = $this->converter->toByteOffset($text, $occurrence->valueRange()->end);
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

    /** @param list<string> $acceptedTypes */
    private function hasTypedReceiver(PhpMethodCall $call, PhpDocument $php, array $acceptedTypes): bool
    {
        $receiver = $call->receiverContext;
        if (null === $receiver->name) {
            return false;
        }
        foreach ($php->typedVariables as $variable) {
            if ($receiver->name !== $variable->name || [] === array_intersect($acceptedTypes, $variable->types)) {
                continue;
            }
            if (PhpMethodReceiverKind::Variable === $receiver->kind
                && \in_array($variable->kind, [PhpTypedVariableKind::Parameter, PhpTypedVariableKind::PromotedProperty], true)
                && $call->scopeStartOffset === $variable->scopeStartOffset
            ) {
                return true;
            }
            if (PhpMethodReceiverKind::ThisProperty === $receiver->kind
                && \in_array($variable->kind, [PhpTypedVariableKind::Property, PhpTypedVariableKind::PromotedProperty], true)
                && $call->className === $variable->className
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $acceptedTypes */
    private function hasTypedVariableAt(PhpDocument $php, array $acceptedTypes, int $offset, string $name, bool $property): bool
    {
        $type = $this->containingType($php, $offset);
        $method = null === $type ? null : $this->containingMethod($php, $type, $offset);
        if (null === $type || null === $method) {
            return false;
        }
        foreach ($php->typedVariables as $variable) {
            if ($name !== $variable->name || [] === array_intersect($acceptedTypes, $variable->types)) {
                continue;
            }
            if ($property
                && \in_array($variable->kind, [PhpTypedVariableKind::Property, PhpTypedVariableKind::PromotedProperty], true)
                && $type->name === $variable->className
            ) {
                return true;
            }
            if (!$property
                && \in_array($variable->kind, [PhpTypedVariableKind::Parameter, PhpTypedVariableKind::PromotedProperty], true)
                && $type->name === $variable->className
                && $method === $variable->methodName
            ) {
                return true;
            }
        }

        return false;
    }

    private function isAbstractControllerAt(PhpDocument $php, int $offset): bool
    {
        $type = $this->containingType($php, $offset);

        return null !== $type
            && null !== $this->containingMethod($php, $type, $offset)
            && self::ABSTRACT_CONTROLLER === $type->parentClassName;
    }

    private function extendsAbstractController(PhpDocument $php, ?string $className): bool
    {
        foreach ($php->typeDeclarations as $type) {
            if ($className === $type->name && self::ABSTRACT_CONTROLLER === $type->parentClassName) {
                return true;
            }
        }

        return false;
    }

    private function containingMethod(PhpDocument $php, PhpTypeDeclaration $type, int $offset): ?string
    {
        $name = null;
        $nameOffset = -1;
        foreach ($php->methodDeclarations as $method) {
            if ($type->name !== $method->className || $method->nameStartOffset > $offset || $method->nameStartOffset <= $nameOffset) {
                continue;
            }
            $name = $method->name;
            $nameOffset = $method->nameStartOffset;
        }

        return $name;
    }

    private function containingType(PhpDocument $php, int $offset): ?PhpTypeDeclaration
    {
        foreach ($php->typeDeclarations as $type) {
            if ($type->contains($offset)) {
                return $type;
            }
        }

        return null;
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
            $key = $symbol->kind()->value.'|'.$symbol->range()->start->line.'|'.$symbol->range()->start->character;
            $unique[$key] = $symbol;
        }

        return array_values($unique);
    }
}
