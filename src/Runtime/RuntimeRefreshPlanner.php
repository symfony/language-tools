<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Index\SourceFileChange;

final class RuntimeRefreshPlanner
{
    /** Maps source index provider names to the runtime metadata sections their facts feed. */
    public const DOMAIN_SECTIONS = [
        'asset' => ['assets'],
        'console' => ['console'],
        'dependency_injection' => ['container'],
        'environment' => ['environment'],
        'event' => ['events', 'container'],
        'messenger' => ['messenger', 'container'],
        'metadata' => ['metadata', 'container'],
        'route' => ['routes'],
        'security' => ['security', 'container'],
        'stimulus' => ['stimulus'],
        'translation' => ['translations'],
        'twig_callable' => ['twig'],
        'twig_component' => ['twig', 'twig_components', 'container'],
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

        $preserveContainer = [] === array_diff($domains, ['asset', 'route', 'stimulus', 'translation']);

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
            return ['asset', 'stimulus'];
        }
        if (str_contains('/'.$path, '/translations/')) {
            return ['translation'];
        }
        if (str_starts_with($path, 'config/routes.') || str_starts_with($path, 'config/routes/')) {
            return ['route'];
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
