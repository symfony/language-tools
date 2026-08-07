<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Index\SourceFileChange;

final class RuntimeRefreshPlanner
{
    private const DOMAIN_SECTIONS = [
        'assets' => ['assets'],
        'dependencyInjection' => ['container'],
        'environment' => ['environment'],
        'events' => ['events', 'container'],
        'messenger' => ['messenger', 'container'],
        'metadata' => ['metadata', 'container'],
        'routes' => ['routes'],
        'security' => ['security', 'container'],
        'stimulus' => ['stimulus'],
        'translations' => ['translations'],
        'twig_components_v2' => ['twig', 'container'],
    ];

    public function plan(string $path, SourceFileChange $change): ?RuntimeRefreshPlan
    {
        if (!$change->requiresRuntimeRefresh()) {
            return null;
        }

        $domains = $change->domains();
        if ([] === $domains) {
            $domains = $this->domainsFromPath($path);
        }
        if ([] === $domains || $this->isAmbiguousConfiguration($path)) {
            return new RuntimeRefreshPlan(RuntimeRefreshMode::Clear);
        }

        $sections = [];
        foreach ($domains as $domain) {
            if (!isset(self::DOMAIN_SECTIONS[$domain])) {
                return new RuntimeRefreshPlan(RuntimeRefreshMode::Clear);
            }
            array_push($sections, ...self::DOMAIN_SECTIONS[$domain]);
        }
        $sections = array_values(array_unique($sections));

        $preserveContainer = [] === array_diff($domains, ['assets', 'routes', 'stimulus', 'translations']);

        return new RuntimeRefreshPlan(
            $preserveContainer ? RuntimeRefreshMode::Reuse : RuntimeRefreshMode::Clear,
            $sections,
            $preserveContainer,
        );
    }

    /** @return list<string> */
    private function domainsFromPath(string $path): array
    {
        if (str_starts_with($path, 'assets/')) {
            return ['assets', 'stimulus'];
        }
        if (str_contains('/'.$path, '/translations/')) {
            return ['translations'];
        }
        if (str_starts_with($path, 'config/routes.') || str_starts_with($path, 'config/routes/')) {
            return ['routes'];
        }

        return [];
    }

    private function isAmbiguousConfiguration(string $path): bool
    {
        return str_starts_with($path, 'config/')
            && !str_starts_with($path, 'config/routes.')
            && !str_starts_with($path, 'config/routes/');
    }
}
