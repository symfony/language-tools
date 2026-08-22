<?php

function configNodeType(object $node): string
{
    $class = basename(str_replace('\\', '/', $node::class));

    return match ($class) {
        'ArrayNode', 'PrototypedArrayNode' => 'array',
        'BooleanNode' => 'boolean',
        'EnumNode' => 'enum',
        'FloatNode' => 'float',
        'IntegerNode' => 'integer',
        'ScalarNode' => 'scalar',
        'VariableNode' => 'variable',
        default => strtolower(preg_replace('/Node$/', '', $class)),
    };
}

function configDefaultSummary(object $node): ?string
{
    if (!method_exists($node, 'hasDefaultValue') || !$node->hasDefaultValue()) {
        return null;
    }
    $value = $node->getDefaultValue();

    return match (true) {
        null === $value => 'null',
        is_array($value) => sprintf('array (%d items)', count($value)),
        is_bool($value) => 'boolean',
        is_float($value) => 'float',
        is_int($value) => 'integer',
        is_string($value) => 'string',
        default => get_debug_type($value),
    };
}

function normalizeConfigExample(mixed $example): mixed
{
    if (null === $example || is_bool($example) || is_float($example) || is_int($example) || is_string($example)) {
        return $example;
    }
    if (is_array($example)) {
        $normalized = [];
        foreach (array_slice($example, 0, 20, true) as $key => $value) {
            $normalized[$key] = normalizeConfigExample($value);
        }

        return $normalized;
    }

    return null;
}

function configNodeNormalizes(object $node, mixed $value): bool
{
    if (!method_exists($node, 'normalize')) {
        return false;
    }
    try {
        $node->normalize($value);
    } catch (Throwable) {
        return false;
    }

    return true;
}

function configNodeAcceptsKey(object $node, string $name, ?object $child = null): bool
{
    $values = [null, [], 'symfony-lsp-probe', true, 1];
    if (null !== $child && method_exists($child, 'getValues')) {
        $allowed = $child->getValues();
        if (is_array($allowed) && [] !== $allowed) {
            array_unshift($values, reset($allowed));
        }
    }
    foreach ($values as $value) {
        if (configNodeNormalizes($node, [$name => $value])) {
            return true;
        }
    }

    return false;
}

function normalizeConfigNode(object $node, int $depth = 0): array
{
    $normalized = [
        'name' => method_exists($node, 'getName') ? (string) $node->getName() : '',
        'type' => configNodeType($node),
        'required' => method_exists($node, 'isRequired') && $node->isRequired(),
        'hasDefault' => method_exists($node, 'hasDefaultValue') && $node->hasDefaultValue(),
        'defaultSummary' => configDefaultSummary($node),
        'info' => method_exists($node, 'getInfo') && is_string($node->getInfo()) ? $node->getInfo() : null,
        'example' => method_exists($node, 'getExample') ? normalizeConfigExample($node->getExample()) : null,
        'deprecated' => method_exists($node, 'isDeprecated') && $node->isDeprecated(),
        'allowedValues' => method_exists($node, 'getValues') ? normalizeConfigExample($node->getValues()) : null,
        'children' => [],
        'prototype' => null,
    ];
    $normalized['accepts'] = [
        'null' => configNodeNormalizes($node, null),
        'true' => configNodeNormalizes($node, true),
        'false' => configNodeNormalizes($node, false),
        'scalar' => configNodeNormalizes($node, 'symfony-lsp-probe'),
        'unknownKeys' => configNodeNormalizes($node, ['symfony_lsp_unknown_probe' => null]),
    ];
    if ($depth >= 32) {
        return $normalized;
    }
    $ownNames = [];
    if (method_exists($node, 'getChildren')) {
        foreach ($node->getChildren() as $child) {
            if (is_object($child)) {
                $normalized['children'][] = normalizeConfigNode($child, $depth + 1);
                if (method_exists($child, 'getName')) {
                    $ownNames[(string) $child->getName()] = true;
                }
            }
        }
    }
    if (method_exists($node, 'getPrototype')) {
        $prototype = $node->getPrototype();
        if (is_object($prototype)) {
            $normalized['prototype'] = normalizeConfigNode($prototype, $depth + 1);
            // some branches normalize prototype entry values on the prototyped
            // node itself, such as the messenger routing string shorthand
            foreach ([['null', null], ['true', true], ['false', false], ['scalar', 'symfony-lsp-probe']] as [$kind, $value]) {
                if (!$normalized['prototype']['accepts'][$kind] && configNodeNormalizes($node, ['symfony_lsp_probe_key' => $value])) {
                    $normalized['prototype']['accepts'][$kind] = true;
                }
            }
        }
    }
    if ('array' === $normalized['type'] && [] !== $normalized['children'] && !$normalized['accepts']['unknownKeys']) {
        mergeShorthandChildren($node, $normalized, $ownNames, $depth);
    }

    return $normalized;
}

/*
 * Bundles such as DoctrineBundle relocate shorthand keys into a prototyped
 * child during normalization (dbal.url becomes connections.default.url).
 * Probing the node with prototype-only keys detects the delegation, and the
 * accepted keys become regular children so validation, completion and hover
 * understand the shorthand.
 */
function mergeShorthandChildren(object $node, array &$normalized, array $ownNames, int $depth): void
{
    if (!method_exists($node, 'getChildren')) {
        return;
    }
    foreach ($node->getChildren() as $child) {
        if (!is_object($child) || !method_exists($child, 'getPrototype')) {
            continue;
        }
        $prototype = $child->getPrototype();
        if (!is_object($prototype) || !method_exists($prototype, 'getChildren')) {
            continue;
        }
        $delegates = false;
        $checked = 0;
        foreach ($prototype->getChildren() as $name => $prototypeChild) {
            if (isset($ownNames[$name]) || !is_object($prototypeChild)) {
                continue;
            }
            if (configNodeAcceptsKey($node, (string) $name, $prototypeChild)) {
                $delegates = true;
                break;
            }
            if (++$checked >= 5) {
                break;
            }
        }
        if (!$delegates) {
            continue;
        }
        foreach ($prototype->getChildren() as $name => $prototypeChild) {
            if (isset($ownNames[$name]) || !is_object($prototypeChild) || !configNodeAcceptsKey($node, (string) $name, $prototypeChild)) {
                continue;
            }
            $normalized['children'][] = normalizeConfigNode($prototypeChild, $depth + 1);
            $ownNames[$name] = true;
        }
    }
}
