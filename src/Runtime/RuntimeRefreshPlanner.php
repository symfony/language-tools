<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Component\Filesystem\Path;
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

    /** Domains the application describes without its container, so the compiled one stays usable. */
    private const CONTAINER_FREE_DOMAINS = ['asset', 'route', 'stimulus', 'translation'];

    /** Whether the change to a project-relative path can make the runtime metadata stale. */
    public function requiresRefresh(string $path, SourceFileChange $change): bool
    {
        if (!$change->requiresRuntimeRefresh() || str_starts_with($path, 'var/') || str_starts_with($path, 'vendor/')) {
            return false;
        }

        $extension = Path::getExtension($path, true);
        if ('php' === $extension || \in_array(basename($path), ['composer.json', 'composer.lock'], true)) {
            return true;
        }

        if (str_starts_with($path, 'assets/')) {
            return true;
        }
        if ('xml' === $extension) {
            return [] !== $change->domains()
                || str_starts_with($path, 'config/')
                || false !== stripos('/'.$path, '/resources/config/');
        }
        if (\in_array($extension, ['ini', 'json', 'xlf', 'xliff'], true)) {
            return $this->isTranslationPath($path);
        }
        if (!\in_array($extension, ['yaml', 'yml'], true)) {
            return false;
        }

        return str_starts_with($path, 'config/') || $this->isTranslationPath($path);
    }

    public function plan(string $path, SourceFileChange $change): RuntimeRefreshPlan
    {
        $domains = $change->domains();
        if ([] === $domains) {
            $domains = $this->domainsFromPath($path);
        }
        if ([] === $domains || $this->isAmbiguousConfiguration($path)) {
            return RuntimeRefreshPlan::rebuild();
        }

        $sections = [];
        foreach ($domains as $domain) {
            if (!isset(self::DOMAIN_SECTIONS[$domain])) {
                return RuntimeRefreshPlan::rebuild();
            }
            array_push($sections, ...self::DOMAIN_SECTIONS[$domain]);
        }
        $sections = array_values(array_unique($sections));

        return [] === array_diff($domains, self::CONTAINER_FREE_DOMAINS)
            ? RuntimeRefreshPlan::preserve($sections)
            : RuntimeRefreshPlan::rebuild($sections);
    }

    /** @return list<string> */
    private function domainsFromPath(string $path): array
    {
        if (str_starts_with($path, 'assets/')) {
            return ['asset', 'stimulus'];
        }
        if ($this->isTranslationPath($path)) {
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

    /** Bundles name their catalog directory `translations` or `Translations`. */
    private function isTranslationPath(string $path): bool
    {
        return false !== stripos('/'.$path, '/translations/');
    }
}
