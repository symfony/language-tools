<?php

namespace Symfony\Lsp\Tools\Dogfood;

/**
 * Hashes a response so that two runs of the same scenario can be compared without storing the response.
 */
final class ResponseFingerprint
{
    private const ORDERED_KEYS = ['documentChanges'];
    private const PROJECT_PLACEHOLDER = '{project}';
    private const VERSION_PLACEHOLDER = '{version}';

    public function hash(mixed $result, string $projectRoot): string
    {
        $normalized = $this->normalize($result, $this->replacements($projectRoot), false);

        return hash('sha256', json_encode($normalized, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param array<string, string> $replacements
     */
    private function normalize(mixed $value, array $replacements, bool $ordered): mixed
    {
        if (\is_string($value)) {
            return strtr($value, $replacements);
        }
        if (!\is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $items = array_map(fn (mixed $item): mixed => $this->normalize($item, $replacements, $ordered), $value);
            if (!$ordered) {
                usort($items, fn (mixed $left, mixed $right): int => $this->encode($left) <=> $this->encode($right));
            }

            return $items;
        }
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string) $key] = 'version' === $key && isset($value['uri'])
                ? self::VERSION_PLACEHOLDER
                : $this->normalize($item, $replacements, \in_array($key, self::ORDERED_KEYS, true));
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function replacements(string $projectRoot): array
    {
        $projectRoot = rtrim($projectRoot, '/');

        return [
            'file://'.str_replace('%2F', '/', rawurlencode($projectRoot)) => self::PROJECT_PLACEHOLDER,
            $projectRoot => self::PROJECT_PLACEHOLDER,
        ];
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
    }
}
