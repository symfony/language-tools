<?php

namespace Symfony\Lsp\Runtime;

/**
 * @phpstan-type RuntimeBridgeTimings array{scope: 'full'|'targeted', bootstrapMilliseconds: float, kernelMilliseconds: float, sectionsMilliseconds: array<string, float>, shutdownMilliseconds: float, totalMilliseconds: float}
 */
final class RuntimeBridgeTimingNormalizer
{
    /**
     * @param list<string>|null      $sections
     * @param 'full'|'targeted'|null $scope
     *
     * @return RuntimeBridgeTimings|null
     */
    public function normalize(mixed $timings, ?array $sections = null, ?string $scope = null): ?array
    {
        if (!\is_array($timings) || !\is_array($timings['sectionsMilliseconds'] ?? null)) {
            return null;
        }
        $scope ??= $timings['scope'] ?? null;
        if (!\in_array($scope, ['full', 'targeted'], true)) {
            return null;
        }

        $normalized = [];
        foreach (['bootstrapMilliseconds', 'kernelMilliseconds', 'shutdownMilliseconds', 'totalMilliseconds'] as $key) {
            $milliseconds = $this->milliseconds($timings[$key] ?? null);
            if (null === $milliseconds) {
                return null;
            }
            $normalized[$key] = $milliseconds;
        }

        $sectionTimings = [];
        foreach ($sections ?? array_keys($timings['sectionsMilliseconds']) as $section) {
            if (!\is_string($section)) {
                continue;
            }
            $milliseconds = $this->milliseconds($timings['sectionsMilliseconds'][$section] ?? null);
            if (null !== $milliseconds) {
                $sectionTimings[$section] = $milliseconds;
            }
        }

        return [
            'scope' => $scope,
            'bootstrapMilliseconds' => $normalized['bootstrapMilliseconds'],
            'kernelMilliseconds' => $normalized['kernelMilliseconds'],
            'sectionsMilliseconds' => $sectionTimings,
            'shutdownMilliseconds' => $normalized['shutdownMilliseconds'],
            'totalMilliseconds' => $normalized['totalMilliseconds'],
        ];
    }

    private function milliseconds(mixed $value): ?float
    {
        if ((!\is_int($value) && !\is_float($value)) || $value < 0 || !is_finite((float) $value)) {
            return null;
        }

        return (float) $value;
    }
}
