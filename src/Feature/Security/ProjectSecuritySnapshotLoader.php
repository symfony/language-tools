<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectSecuritySnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly SecurityIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'security';
    }

    public function load(Project $project, array $snapshot): void
    {
        $sections = $snapshot['sections'] ?? null;
        $section = \is_array($sections) ? ($sections['security'] ?? null) : null;
        if (!\is_array($section)) {
            return;
        }
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
                array_values(array_filter(\is_array($item['authenticators'] ?? null) ? $item['authenticators'] : [], 'is_string')),
            );
        }
        $providers = [];
        foreach (\is_array($section['providers'] ?? null) ? $section['providers'] : [] as $item) {
            if (\is_array($item) && \is_string($item['name'] ?? null) && \is_string($item['type'] ?? null)) {
                $providers[] = new SecurityUserProvider($item['name'], $item['type']);
            }
        }
        $roles = [];
        foreach (\is_array($section['roles'] ?? null) ? $section['roles'] : [] as $item) {
            if (\is_array($item) && \is_string($item['name'] ?? null)) {
                $roles[] = new SecurityRole($item['name'], array_values(array_filter(\is_array($item['inheritedRoles'] ?? null) ? $item['inheritedRoles'] : [], 'is_string')));
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
