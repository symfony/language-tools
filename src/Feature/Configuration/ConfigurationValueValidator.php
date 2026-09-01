<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Lsp\Feature\Environment\EnvironmentExpressionParser;
use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Project\Project;

final class ConfigurationValueValidator
{
    public function __construct(
        private readonly EnvironmentIndexRegistry $environmentIndexes,
        private readonly EnvironmentExpressionParser $environmentExpressions,
    ) {
    }

    public function environmentType(Project $project, string $value): ?string
    {
        $expression = $this->environmentExpressions->parse(trim($value, " \t\"'"));
        if (null === $expression) {
            return null;
        }
        if ([] === $expression->processorChain) {
            return 'string';
        }

        return $this->environmentIndexes->forProject($project)->processors()[$expression->processorChain[0]] ?? null;
    }

    public function acceptsType(ConfigurationNode $node, string $actual): bool
    {
        $expected = 'boolean' === $node->type ? 'bool' : $node->type;
        if (!\in_array($expected, ['array', 'bool', 'float', 'integer'], true)) {
            return true;
        }
        $expected = 'integer' === $expected ? 'int' : $expected;
        $actualTypes = preg_split('/[|&]/', str_replace(['?', '"'], '', $actual), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        if ('array' === $expected && $node->acceptsScalar() && [] !== array_diff($actualTypes, ['array'])) {
            return true;
        }

        return \in_array($expected, $actualTypes, true) || ('float' === $expected && \in_array('int', $actualTypes, true));
    }

    public function acceptsValue(ConfigurationNode $node, string $value): bool
    {
        $source = trim($value);
        $plain = trim($source, "\"'");
        if (str_contains($plain, '%') || str_starts_with($plain, '$')) {
            return true;
        }
        if ([] !== $node->allowedEnumCases && null !== $enumCase = $this->enumCase($source)) {
            return $node->allowedValuesTruncated || \in_array($enumCase, $node->allowedEnumCases, true);
        }
        $literal = $this->literal($source);
        if (null === $literal && $node->acceptsNull()) {
            return true;
        }
        if (!$node->allowedValuesTruncated) {
            if ([] !== $node->allowedValues && !\in_array($literal, $node->allowedValues, true)) {
                return false;
            }
            if ([] === $node->allowedValues && [] !== $node->allowedEnumCases) {
                return false;
            }
        }

        return match ($node->type) {
            'boolean' => \is_bool($literal) || \in_array($literal, [0, 1], true),
            'integer' => \is_int($literal),
            'float' => \is_int($literal) || \is_float($literal),
            'array' => $this->acceptsArrayValue($node, $plain),
            default => true,
        };
    }

    public function acceptsResolvedValue(ConfigurationNode $node, mixed $value): bool
    {
        if ($value instanceof TaggedValue) {
            if (!\in_array($value->getTag(), ['php/enum', 'symfony-lsp/php-enum'], true) || !\is_string($value->getValue()) || [] === $node->allowedEnumCases) {
                return true;
            }

            return $node->allowedValuesTruncated || \in_array(ltrim($value->getValue(), '\\'), $node->allowedEnumCases, true);
        }
        if (\is_string($value) && (str_contains($value, '%') || str_starts_with($value, '$'))) {
            return true;
        }
        if (null === $value && $node->acceptsNull()) {
            return true;
        }
        if (!$node->allowedValuesTruncated) {
            if ([] !== $node->allowedValues && !\in_array($value, $node->allowedValues, true)) {
                return false;
            }
            if ([] === $node->allowedValues && [] !== $node->allowedEnumCases) {
                return false;
            }
        }

        return match ($node->type) {
            'boolean' => \is_bool($value) || \in_array($value, [0, 1], true),
            'integer' => \is_int($value),
            'float' => \is_int($value) || \is_float($value),
            'array' => $this->acceptsResolvedArrayValue($node, $value),
            default => true,
        };
    }

    private function enumCase(string $source): ?string
    {
        if (1 !== preg_match('/^!php\/enum\s+(.+)$/', $source, $match)) {
            return null;
        }

        return ltrim(trim($match[1], " \t\"'"), '\\');
    }

    private function literal(string $source): string|int|float|bool|null
    {
        $length = \strlen($source);
        if ($length >= 2 && \in_array($source[0], ['"', "'"], true) && str_ends_with($source, $source[0])) {
            return substr($source, 1, -1);
        }

        return match (strtolower($source)) {
            '~', 'null' => null,
            'true' => true,
            'false' => false,
            default => match (true) {
                1 === preg_match('/^-?\d+$/', $source) => (int) $source,
                is_numeric($source) => (float) $source,
                default => $source,
            },
        };
    }

    /**
     * Array nodes commonly normalize scalars, such as null and true enabling a
     * feature or a string expanding to a shorthand structure.
     */
    private function acceptsArrayValue(ConfigurationNode $node, string $plain): bool
    {
        if ('' === $plain || str_starts_with($plain, '[') || str_starts_with($plain, '{')) {
            return true;
        }

        return match (strtolower($plain)) {
            '~', 'null' => $node->acceptsNull(),
            'true' => $node->acceptsTrue(),
            'false' => $node->acceptsFalse(),
            default => $node->acceptsScalar(),
        };
    }

    private function acceptsResolvedArrayValue(ConfigurationNode $node, mixed $value): bool
    {
        if (\is_array($value)) {
            return true;
        }

        return match ($value) {
            null => $node->acceptsNull(),
            true => $node->acceptsTrue(),
            false => $node->acceptsFalse(),
            default => \is_scalar($value) ? $node->acceptsScalar() : true,
        };
    }
}
