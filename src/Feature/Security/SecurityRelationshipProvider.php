<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class SecurityRelationshipProvider implements DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly SecurityIndexRegistry $indexes,
        private readonly SecuritySourceIndexRegistry $sourceIndexes,
        private readonly SecuritySymbolResolver $symbols,
    ) {
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->symbols->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        $lines = match ($symbol->kind) {
            SecuritySymbolKind::Firewall => $this->firewallHover($index, $symbol->name),
            SecuritySymbolKind::Provider => $this->providerHover($index, $symbol->name),
            SecuritySymbolKind::Role => $this->roleHover($index, $symbol->name),
        };

        return [] === $lines ? null : $this->protocol->markdownHover(implode("\n", $lines));
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->symbols->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;
        $declarations = array_filter(
            $this->sourceIndexes->forProject($project)->symbols($symbol->kind, $symbol->name),
            static fn (SecuritySourceSymbol $candidate): bool => $candidate->declaration,
        );

        return array_map(fn (SecuritySourceSymbol $candidate): array => $this->protocol->location($candidate->uri, $candidate->range), array_values($declarations));
    }

    public function references(array $params): ?array
    {
        $resolved = $this->symbols->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $project] = $resolved;

        return array_map(fn (SecuritySourceSymbol $candidate): array => $this->protocol->location($candidate->uri, $candidate->range), $this->sourceIndexes->forProject($project)->symbols($symbol->kind, $symbol->name));
    }

    /** @return list<string> */
    private function firewallHover(SecurityIndex $index, string $name): array
    {
        $firewall = $index->firewall($name);
        if (null === $firewall) {
            return [];
        }

        return [
            'Security firewall: `'.$name.'`',
            '',
            'Enabled: '.($firewall->enabled ? 'yes' : 'no'),
            '',
            'Provider: '.(null === $firewall->provider ? 'none' : '`'.$firewall->provider.'`'),
            '',
            'Stateless: '.($firewall->stateless ? 'yes' : 'no'),
            '',
            'Lazy: '.($firewall->lazy ? 'yes' : 'no'),
            '',
            'Authenticators: '.([] === $firewall->authenticators ? 'none' : '`'.implode('`, `', $firewall->authenticators).'`'),
        ];
    }

    /** @return list<string> */
    private function providerHover(SecurityIndex $index, string $name): array
    {
        $provider = $index->provider($name);
        if (null === $provider) {
            return [];
        }
        $firewalls = array_values(array_filter($index->firewalls(), static fn (SecurityFirewall $firewall): bool => $firewall->provider === $name));

        return [
            'Security user provider: `'.$name.'`',
            '',
            'Type: `'.$provider->type.'`',
            '',
            'Firewalls: '.([] === $firewalls ? 'none' : '`'.implode('`, `', array_map(static fn (SecurityFirewall $firewall): string => $firewall->name, $firewalls)).'`'),
        ];
    }

    /** @return list<string> */
    private function roleHover(SecurityIndex $index, string $name): array
    {
        $role = $index->role($name);
        if (null === $role) {
            return [];
        }
        $parents = [];
        foreach ($index->roles() as $candidate) {
            if (\in_array($name, $candidate->inheritedRoles, true)) {
                $parents[] = $candidate->name;
            }
        }
        $voters = array_map(static fn (SecurityVoter $voter): string => $voter->className, $index->voters());

        return [
            'Security role: `'.$name.'`',
            '',
            'Inherits: '.([] === $role->inheritedRoles ? 'none' : '`'.implode('`, `', $role->inheritedRoles).'`'),
            '',
            'Inherited by: '.([] === $parents ? 'none' : '`'.implode('`, `', $parents).'`'),
            '',
            'Registered voters: '.([] === $voters ? 'none' : '`'.implode('`, `', $voters).'`'),
        ];
    }
}
