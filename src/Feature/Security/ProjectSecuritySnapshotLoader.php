<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\RuntimeSnapshotValues;

final class ProjectSecuritySnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly SecurityIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'security';
    }

    public function load(Project $project, array $section): void
    {
        $firewalls = [];
        foreach (\is_array($section['firewalls'] ?? null) ? $section['firewalls'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['name'] ?? null)) {
                continue;
            }
            $firewalls[] = new SecurityFirewall(
                $item['name'],
                \is_string($item['provider'] ?? null) ? $item['provider'] : null,
                true === ($item['enabled'] ?? false),
                true === ($item['stateless'] ?? false),
                true === ($item['lazy'] ?? false),
                RuntimeSnapshotValues::stringList($item['authenticators'] ?? null),
            );
        }
        $providers = [];
        foreach (\is_array($section['providers'] ?? null) ? $section['providers'] : [] as $item) {
            if (\is_array($item) && \is_string($item['name'] ?? null) && \is_string($item['type'] ?? null)) {
                $providers[] = new SecurityUserProviderDeclaration($item['name'], $item['type']);
            }
        }
        $roles = [];
        foreach (\is_array($section['roles'] ?? null) ? $section['roles'] : [] as $item) {
            if (\is_array($item) && \is_string($item['name'] ?? null)) {
                $roles[] = new SecurityRole($item['name'], RuntimeSnapshotValues::stringList($item['inheritedRoles'] ?? null));
            }
        }
        $voters = [];
        foreach (\is_array($section['voters'] ?? null) ? $section['voters'] : [] as $item) {
            if (\is_array($item) && \is_string($item['class'] ?? null)) {
                $voters[] = new SecurityVoter($item['class']);
            }
        }
        $this->indexes->forProject($project)->replace($firewalls, $providers, $roles, $voters, true === ($section['complete'] ?? false));
    }
}
