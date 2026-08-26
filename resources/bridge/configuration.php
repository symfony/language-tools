<?php

function symfonyLspBridgeConfigNodeType(object $node): string
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

function symfonyLspBridgeConfigDefaultSummary(object $node): ?string
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

function symfonyLspBridgeNormalizeConfigExample(mixed $example): mixed
{
    if (null === $example || is_bool($example) || is_float($example) || is_int($example) || is_string($example)) {
        return $example;
    }
    if (is_array($example)) {
        $normalized = [];
        foreach (array_slice($example, 0, 20, true) as $key => $value) {
            $normalized[$key] = symfonyLspBridgeNormalizeConfigExample($value);
        }

        return $normalized;
    }

    return null;
}

function symfonyLspBridgeConfigNodeNormalizes(object $node, mixed $value): bool
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

function symfonyLspBridgeConfigNodeAcceptsKey(object $node, string $name, ?object $child = null): bool
{
    $values = [null, [], 'symfony-lsp-probe', true, 1];
    if (null !== $child && method_exists($child, 'getValues')) {
        $allowed = $child->getValues();
        if (is_array($allowed) && [] !== $allowed) {
            array_unshift($values, reset($allowed));
        }
    }
    foreach ($values as $value) {
        if (symfonyLspBridgeConfigNodeNormalizes($node, [$name => $value])) {
            return true;
        }
    }

    return false;
}

function symfonyLspBridgeConfigNodeNormalizesKeys(object $node): bool
{
    if (!property_exists($node, 'normalizeKeys')) {
        return true;
    }
    try {
        $property = (new ReflectionObject($node))->getProperty('normalizeKeys');
        $normalizeKeys = $property->getValue($node);
    } catch (ReflectionException) {
        return true;
    }

    return !is_bool($normalizeKeys) || $normalizeKeys;
}

function symfonyLspBridgeNormalizeConfigNode(object $node, int $depth = 0): array
{
    $normalized = [
        'name' => method_exists($node, 'getName') ? (string) $node->getName() : '',
        'type' => symfonyLspBridgeConfigNodeType($node),
        'required' => method_exists($node, 'isRequired') && $node->isRequired(),
        'hasDefault' => method_exists($node, 'hasDefaultValue') && $node->hasDefaultValue(),
        'defaultSummary' => symfonyLspBridgeConfigDefaultSummary($node),
        'info' => method_exists($node, 'getInfo') && is_string($node->getInfo()) ? $node->getInfo() : null,
        'example' => method_exists($node, 'getExample') ? symfonyLspBridgeNormalizeConfigExample($node->getExample()) : null,
        'deprecated' => method_exists($node, 'isDeprecated') && $node->isDeprecated(),
        'allowedValues' => method_exists($node, 'getValues') ? symfonyLspBridgeNormalizeConfigExample($node->getValues()) : null,
        'children' => [],
        'prototype' => null,
        'aliases' => [],
        'keyAttribute' => method_exists($node, 'getKeyAttribute') && is_string($node->getKeyAttribute()) ? $node->getKeyAttribute() : null,
        'normalizeKeys' => symfonyLspBridgeConfigNodeNormalizesKeys($node),
    ];
    $normalized['accepts'] = [
        'null' => symfonyLspBridgeConfigNodeNormalizes($node, null),
        'true' => symfonyLspBridgeConfigNodeNormalizes($node, true),
        'false' => symfonyLspBridgeConfigNodeNormalizes($node, false),
        'scalar' => symfonyLspBridgeConfigNodeNormalizes($node, 'symfony-lsp-probe'),
        'unknownKeys' => symfonyLspBridgeConfigNodeNormalizes($node, ['symfony_lsp_unknown_probe' => null]),
    ];
    if (method_exists($node, 'getXmlRemappings')) {
        foreach ($node->getXmlRemappings() as $remapping) {
            if (is_array($remapping) && is_string($remapping[0] ?? null) && is_string($remapping[1] ?? null)) {
                $normalized['aliases'][$remapping[0]] = $remapping[1];
            }
        }
        ksort($normalized['aliases']);
    }
    if ($depth >= 32) {
        return $normalized;
    }
    $ownNames = [];
    if (method_exists($node, 'getChildren')) {
        foreach ($node->getChildren() as $child) {
            if (is_object($child)) {
                $normalized['children'][] = symfonyLspBridgeNormalizeConfigNode($child, $depth + 1);
                if (method_exists($child, 'getName')) {
                    $ownNames[(string) $child->getName()] = true;
                }
            }
        }
    }
    if (method_exists($node, 'getPrototype')) {
        $prototype = $node->getPrototype();
        if (is_object($prototype)) {
            $normalized['prototype'] = symfonyLspBridgeNormalizeConfigNode($prototype, $depth + 1);
            // some branches normalize prototype entry values on the prototyped
            // node itself, such as the messenger routing string shorthand
            foreach ([['null', null], ['true', true], ['false', false], ['scalar', 'symfony-lsp-probe']] as [$kind, $value]) {
                if (!$normalized['prototype']['accepts'][$kind] && symfonyLspBridgeConfigNodeNormalizes($node, ['symfony_lsp_probe_key' => $value])) {
                    $normalized['prototype']['accepts'][$kind] = true;
                }
            }
        }
    }
    if ('array' === $normalized['type'] && [] !== $normalized['children'] && !$normalized['accepts']['unknownKeys']) {
        symfonyLspBridgeMergeShorthandChildren($node, $normalized, $ownNames, $depth);
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
function symfonyLspBridgeMergeShorthandChildren(object $node, array &$normalized, array $ownNames, int $depth): void
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
            if (symfonyLspBridgeConfigNodeAcceptsKey($node, (string) $name, $prototypeChild)) {
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
            if (isset($ownNames[$name]) || !is_object($prototypeChild) || !symfonyLspBridgeConfigNodeAcceptsKey($node, (string) $name, $prototypeChild)) {
                continue;
            }
            $normalized['children'][] = symfonyLspBridgeNormalizeConfigNode($prototypeChild, $depth + 1);
            $ownNames[$name] = true;
        }
    }
}
