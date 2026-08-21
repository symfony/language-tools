<?php

function bridgeRoutesSection(SymfonyLspBridgeContext $context): ?array
{
    if (!class_exists(Symfony\Component\Console\Input\ArrayInput::class)
        || !class_exists(Symfony\Component\Console\Output\BufferedOutput::class)
    ) {
        $context->addError('routes');
    } else {
        try {
            $application = $context->application();
            $routes = runJsonCommand($application, [
                'command' => 'debug:router',
                '--format' => 'json',
                '--show-aliases' => true,
                ...$context->commandOptions(),
            ]);

            $items = [];
            foreach ($routes as $name => $route) {
                if (!is_string($name) || !is_array($route)) {
                    continue;
                }

                $methods = is_array($route['methods'] ?? null)
                    ? array_values($route['methods'])
                    : splitDebugValues($route['method'] ?? null);
                $schemes = is_array($route['schemes'] ?? null)
                    ? array_values($route['schemes'])
                    : splitDebugValues($route['scheme'] ?? null);
                $host = is_string($route['host'] ?? null) && !in_array($route['host'], ['', 'ANY'], true)
                    ? $route['host']
                    : null;
                $defaults = is_array($route['defaults'] ?? null) ? $route['defaults'] : [];
                $requirements = [];
                foreach (is_array($route['requirements'] ?? null) ? $route['requirements'] : [] as $key => $value) {
                    if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
                        $requirements[$key] = (string) $value;
                    }
                }
                $canonical = null;
                $canonicalDefault = $defaults['_canonical_route'] ?? null;
                $locale = $defaults['_locale'] ?? null;
                if (is_string($canonicalDefault) && is_string($locale) && $name === $canonicalDefault.'.'.$locale) {
                    $canonical = $canonicalDefault;
                }
                $alias = is_string($route['alias'] ?? null)
                    ? $route['alias']
                    : (is_string($route['aliasFor'] ?? null) ? $route['aliasFor'] : null);
                $items[] = [
                    'name' => $name,
                    'path' => is_string($route['path'] ?? null) ? $route['path'] : null,
                    'methods' => $methods,
                    'schemes' => $schemes,
                    'host' => $host,
                    'controller' => is_string($defaults['_controller'] ?? null)
                        ? $defaults['_controller']
                        : null,
                    'defaults' => array_values(array_filter(
                        array_keys($defaults),
                        static fn (mixed $key): bool => is_string($key),
                    )),
                    'requirements' => $requirements,
                    'canonical' => $canonical,
                    'alias' => $alias,
                ];
            }

            usort($items, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);
            $section = [
                'complete' => true,
                'generation' => hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
                'items' => $items,
                'resources' => [],
                'warnings' => [],
            ];
        } catch (Throwable) {
            $context->addError('routes');
        }
    }

    return $section ?? null;
}
