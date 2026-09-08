<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ResponseFingerprint
{
    private const ORDERED_KEYS = ['documentChanges'];
    private const OPAQUE_KEYS = ['arguments', 'data'];
    private const PROJECT_PLACEHOLDER = '{project}';
    private const VERSION_PLACEHOLDER = '{version}';

    public function hash(mixed $result, string $projectRoot): string
    {
        $normalized = $this->normalize($result, $this->replacements($projectRoot), false);

        return hash('sha256', json_encode($normalized, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, string> $replacements
     */
    private function normalize(mixed $value, array $replacements, bool $ordered, bool $opaque = false): mixed
    {
        if (\is_string($value)) {
            return strtr($value, $replacements);
        }
        if (!\is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $items = array_map(fn (mixed $item): mixed => $this->normalize($item, $replacements, $opaque, $opaque), $value);
            if (!$ordered) {
                usort($items, fn (mixed $left, mixed $right): int => $this->encode($left) <=> $this->encode($right));
            }

            return $items;
        }
        $normalized = [];
        foreach ($value as $key => $item) {
            $childOpaque = $opaque || \in_array($key, self::OPAQUE_KEYS, true);
            $normalized[(string) $key] = !$opaque && 'version' === $key && isset($value['uri'])
                ? self::VERSION_PLACEHOLDER
                : $this->normalize($item, $replacements, $childOpaque || \in_array($key, self::ORDERED_KEYS, true), $childOpaque);
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
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
