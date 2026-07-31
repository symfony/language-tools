<?php

function inferHandlerMessages(string $class, string $method): array
{
    if (!class_exists($class) || !method_exists($class, $method)) {
        return [];
    }
    try {
        $parameters = (new ReflectionMethod($class, $method))->getParameters();
        $type = ($parameters[0] ?? null)?->getType();
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        $messages = [];
        foreach ($types as $candidate) {
            if ($candidate instanceof ReflectionNamedType && !$candidate->isBuiltin()) {
                $messages[] = $candidate->getName();
            }
        }

        return $messages;
    } catch (Throwable) {
        return [];
    }
}

function definitionTagParameters(array $definition, string $tagName): array
{
    $parameters = [];
    foreach (is_array($definition['tags'] ?? null) ? $definition['tags'] : [] as $key => $tag) {
        if (is_array($tag) && $tagName === ($tag['name'] ?? null)) {
            $parameters[] = is_array($tag['parameters'] ?? null) ? $tag['parameters'] : [];
        } elseif ($tagName === $key) {
            foreach (is_array($tag) ? $tag : [] as $attributes) {
                $parameters[] = is_array($attributes) ? $attributes : [];
            }
        }
    }

    return $parameters;
}

function normalizeServices(array $container, array $types): array
{
    $services = [];
    $definitions = $container['definitions'] ?? $container['services'] ?? $container;
    foreach (is_array($definitions) ? $definitions : [] as $key => $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $id = is_string($definition['id'] ?? null)
            ? $definition['id']
            : (is_string($definition['name'] ?? null)
                ? $definition['name']
                : (is_string($key) ? $key : null));
        if (null === $id || in_array($id, ['aliases', 'definitions', 'services'], true)) {
            continue;
        }

        $alias = is_string($definition['alias'] ?? null)
            ? $definition['alias']
            : null;
        $services[$id] = normalizeService($id, $definition, $alias);
    }

    foreach (is_array($container['services'] ?? null) ? $container['services'] : [] as $id => $className) {
        if (is_string($id) && is_string($className)) {
            $services[$id] = normalizeService($id, ['class' => $className], null);
        }
    }

    foreach (is_array($container['aliases'] ?? null) ? $container['aliases'] : [] as $key => $alias) {
        $metadata = is_array($alias) ? $alias : [];
        $id = is_string($metadata['id'] ?? null)
            ? $metadata['id']
            : (is_string($metadata['name'] ?? null)
                ? $metadata['name']
                : (is_string($key) ? $key : null));
        $target = is_string($alias)
            ? $alias
            : (is_string($metadata['service'] ?? null)
                ? $metadata['service']
                : (is_string($metadata['target'] ?? null) ? $metadata['target'] : null));
        if (null !== $id) {
            $services[$id] = normalizeService($id, $metadata, $target);
        }
    }

    $typesByService = normalizeAutowiringTypes($types);
    foreach ($services as $id => $service) {
        $service['autowiringTypes'] = $typesByService[$id] ?? [];
        $services[$id] = $service;
    }

    ksort($services);

    return array_values($services);
}

function normalizeService(string $id, array $metadata, ?string $alias): array
{
    $tags = [];
    foreach (is_array($metadata['tags'] ?? null) ? $metadata['tags'] : [] as $key => $tag) {
        $name = is_string($key)
            ? $key
            : (is_string($tag) ? $tag : (is_array($tag) && is_string($tag['name'] ?? null) ? $tag['name'] : null));
        if (null !== $name) {
            $tags[] = $name;
        }
    }
    $tags = array_values(array_unique($tags));
    sort($tags);

    $decorates = is_string($metadata['decorates'] ?? null)
        ? $metadata['decorates']
        : (is_array($metadata['decoration'] ?? null) && is_string($metadata['decoration']['service'] ?? null)
            ? $metadata['decoration']['service']
            : null);
    $decorationStack = [];
    foreach (is_array($metadata['decoration_stack'] ?? null) ? $metadata['decoration_stack'] : [] as $decorator) {
        if (is_array($decorator) && is_string($decorator['id'] ?? null)) {
            $decorationStack[] = $decorator['id'];
        }
    }

    return [
        'id' => $id,
        'class' => is_string($metadata['class'] ?? null) ? $metadata['class'] : null,
        'alias' => $alias,
        'public' => is_bool($metadata['public'] ?? null) ? $metadata['public'] : null,
        'lazy' => is_bool($metadata['lazy'] ?? null)
            ? $metadata['lazy']
            : (is_string($metadata['lazy'] ?? null) && '' !== $metadata['lazy'] ? true : null),
        'deprecation' => normalizeDeprecation($metadata['deprecation_message'] ?? $metadata['deprecated'] ?? $metadata['deprecation'] ?? null),
        'tags' => $tags,
        'decorates' => $decorates,
        'decorationStack' => array_values(array_unique($decorationStack)),
        'autowiringTypes' => [],
    ];
}

function normalizeAutowiringTypes(array $output): array
{
    $typesByService = [];
    if (array_key_exists('definitions', $output) || array_key_exists('aliases', $output)) {
        foreach (is_array($output['definitions'] ?? null) ? $output['definitions'] : [] as $type => $_) {
            if (is_string($type)) {
                $typesByService[$type][] = $type;
            }
        }
        foreach (is_array($output['aliases'] ?? null) ? $output['aliases'] : [] as $type => $alias) {
            if (is_string($type) && is_array($alias) && is_string($alias['service'] ?? null)) {
                $typesByService[$alias['service']][] = $type;
            }
        }
        foreach (is_array($output['services'] ?? null) ? $output['services'] : [] as $type => $_) {
            if (is_string($type)) {
                $typesByService[$type][] = $type;
            }
        }
    } else {
        $types = is_array($output['types'] ?? null) ? $output['types'] : $output;
        foreach ($types as $key => $services) {
            $type = is_string($key)
                ? $key
                : (is_array($services) && is_string($services['type'] ?? null) ? $services['type'] : null);
            if (null === $type) {
                continue;
            }

            $serviceIds = is_array($services) && array_key_exists('services', $services)
                ? $services['services']
                : $services;
            foreach (serviceIds($serviceIds) as $serviceId) {
                $typesByService[$serviceId][] = $type;
            }
        }
    }

    foreach ($typesByService as $serviceId => $serviceTypes) {
        $serviceTypes = array_values(array_unique($serviceTypes));
        sort($serviceTypes);
        $typesByService[$serviceId] = $serviceTypes;
    }

    return $typesByService;
}

function serviceIds(mixed $services): array
{
    if (is_string($services)) {
        return [$services];
    }
    if (!is_array($services)) {
        return [];
    }
    if (is_string($services['id'] ?? null)) {
        return [$services['id']];
    }
    if (is_string($services['service'] ?? null)) {
        return [$services['service']];
    }

    $ids = [];
    foreach ($services as $service) {
        array_push($ids, ...serviceIds($service));
    }

    return array_values(array_unique($ids));
}

function normalizeParameters(array $output): array
{
    $parameters = $output['parameters'] ?? $output;
    $deprecations = [];
    if (is_array($parameters) && is_array($parameters['_deprecations'] ?? null)) {
        foreach ($parameters['_deprecations'] as $name => $deprecation) {
            if (is_string($name) && is_string($deprecation) && str_starts_with($deprecation, 'Since ')) {
                $deprecations[$name] = $deprecation;
            }
        }
    }

    $items = [];
    foreach (is_array($parameters) ? $parameters : [] as $name => $_) {
        if (!is_string($name) || '_deprecations' === $name) {
            continue;
        }

        $items[$name] = [
            'name' => $name,
            'deprecation' => $deprecations[$name] ?? null,
        ];
    }
    ksort($items);

    return array_values($items);
}

function normalizeDeprecation(mixed $deprecation): ?string
{
    if (is_string($deprecation)) {
        return '' !== $deprecation ? $deprecation : null;
    }
    if (true === $deprecation) {
        return 'Deprecated';
    }
    if (is_array($deprecation) && is_string($deprecation['message'] ?? null)) {
        return $deprecation['message'];
    }

    return null;
}
