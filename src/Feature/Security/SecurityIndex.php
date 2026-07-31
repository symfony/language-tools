<?php

namespace Symfony\Lsp\Feature\Security;

final class SecurityIndex
{
    /** @var array<string, SecurityFirewall> */
    private array $firewalls = [];
    /** @var array<string, SecurityUserProvider> */
    private array $providers = [];
    /** @var array<string, SecurityRole> */
    private array $roles = [];
    /** @var list<SecurityVoter> */
    private array $voters = [];
    private bool $complete = false;

    /**
     * @param list<SecurityFirewall>     $firewalls
     * @param list<SecurityUserProvider> $providers
     * @param list<SecurityRole>         $roles
     * @param list<SecurityVoter>        $voters
     */
    public function replace(array $firewalls, array $providers, array $roles, array $voters, bool $complete): void
    {
        $this->firewalls = [];
        foreach ($firewalls as $firewall) {
            $this->firewalls[$firewall->name()] = $firewall;
        }
        ksort($this->firewalls);
        $this->providers = [];
        foreach ($providers as $provider) {
            $this->providers[$provider->name()] = $provider;
        }
        ksort($this->providers);
        $this->roles = [];
        foreach ($roles as $role) {
            $this->roles[$role->name()] = $role;
        }
        ksort($this->roles);
        $this->voters = $voters;
        $this->complete = $complete;
    }

    /** @return list<SecurityFirewall> */
    public function firewalls(): array
    {
        return array_values($this->firewalls);
    }

    public function firewall(string $name): ?SecurityFirewall
    {
        return $this->firewalls[$name] ?? null;
    }

    /** @return list<SecurityUserProvider> */
    public function providers(): array
    {
        return array_values($this->providers);
    }

    public function provider(string $name): ?SecurityUserProvider
    {
        return $this->providers[$name] ?? null;
    }

    /** @return list<SecurityRole> */
    public function roles(): array
    {
        return array_values($this->roles);
    }

    public function role(string $name): ?SecurityRole
    {
        return $this->roles[$name] ?? null;
    }

    /** @return list<SecurityVoter> */
    public function voters(): array
    {
        return $this->voters;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
