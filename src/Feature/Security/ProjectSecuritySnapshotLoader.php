<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

final class ProjectSecuritySnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly SecurityIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'security';
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $firewalls = [];
        foreach ($section->items('firewalls', 'name') as $item) {
            $firewalls[] = new SecurityFirewall(
                $item->string('name'),
                $item->optionalString('provider'),
                $item->bool('enabled'),
                $item->bool('stateless'),
                $item->bool('lazy'),
                $item->strings('authenticators'),
            );
        }
        $providers = [];
        foreach ($section->items('providers', 'name', 'type') as $item) {
            $providers[] = new SecurityUserProviderDeclaration($item->string('name'), $item->string('type'));
        }
        $roles = [];
        foreach ($section->items('roles', 'name') as $item) {
            $roles[] = new SecurityRole($item->string('name'), $item->strings('inheritedRoles'));
        }
        $voters = [];
        foreach ($section->items('voters', 'class') as $item) {
            $voters[] = new SecurityVoter($item->string('class'));
        }
        $this->indexes->forProject($project)->replace($firewalls, $providers, $roles, $voters, $section->complete());
    }
}
