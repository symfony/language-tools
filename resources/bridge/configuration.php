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
    if ($depth >= 32) {
        return $normalized;
    }
    if (method_exists($node, 'getChildren')) {
        foreach ($node->getChildren() as $child) {
            if (is_object($child)) {
                $normalized['children'][] = normalizeConfigNode($child, $depth + 1);
            }
        }
    }
    if (method_exists($node, 'getPrototype')) {
        $prototype = $node->getPrototype();
        if (is_object($prototype)) {
            $normalized['prototype'] = normalizeConfigNode($prototype, $depth + 1);
        }
    }

    return $normalized;
}
