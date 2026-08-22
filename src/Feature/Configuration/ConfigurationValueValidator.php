<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Project\Project;

final class ConfigurationValueValidator
{
    public function __construct(
        private readonly EnvironmentIndexRegistry $environmentIndexes,
    ) {
    }

    public function environmentType(Project $project, string $value): ?string
    {
        $value = trim($value, " \t\"'");
        if (1 !== preg_match('/^%env\(([^)]+)\)%$/', $value, $match)) {
            return null;
        }
        $separator = strpos($match[1], ':');
        if (false === $separator) {
            return 'string';
        }

        return $this->environmentIndexes->forProject($project)->processors()[substr($match[1], 0, $separator)] ?? null;
    }

    public function acceptsType(ConfigurationNode $node, string $actual): bool
    {
        $expected = 'boolean' === $node->type() ? 'bool' : $node->type();
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
        $plain = trim($value, " \t\"'");
        if (str_contains($plain, '%') || str_starts_with($plain, '$')) {
            return true;
        }
        if (\in_array(strtolower($plain), ['~', 'null'], true) && $node->acceptsNull()) {
            return true;
        }
        if ([] !== $node->allowedValues()) {
            $allowed = false;
            foreach ($node->allowedValues() as $value) {
                if ($plain === (string) $value) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return false;
            }
        }

        return match ($node->type()) {
            'boolean' => \in_array(strtolower($plain), ['true', 'false', 'yes', 'no', '0', '1'], true),
            'integer' => 1 === preg_match('/^-?\d+$/', $plain),
            'float' => is_numeric($plain),
            'array' => $this->acceptsArrayValue($node, $plain),
            default => true,
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
            'true', 'yes' => $node->acceptsTrue(),
            'false', 'no' => $node->acceptsFalse(),
            default => $node->acceptsScalar(),
        };
    }
}
