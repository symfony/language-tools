<?php

namespace Symfony\Lsp\Tools\Dogfood;

/**
 * Turns a harness report into a support score: the fraction of expected
 * request kinds that returned a result, averaged per category and then per
 * project so categories with many probes do not dominate.
 */
final class SupportScorer
{
    private const DEFAULT_EXPECTED = ['completion', 'hover', 'definition', 'references'];

    /**
     * Form and constraint options are resolver metadata without a navigable
     * declaration, so definition and references are not expected.
     */
    private const EXPECTED_OVERRIDES = [
        'form.option.php' => ['completion', 'hover'],
        'constraint.option.php' => ['completion', 'hover'],
    ];

    /**
     * Exclusions remove verified-impossible request kinds from the
     * denominator, such as build artifacts absent from a fresh checkout, so
     * a full score means everything achievable is supported.
     *
     * @param list<array<array-key, mixed>> $exclusions
     */
    public function __construct(private readonly array $exclusions = [])
    {
    }

    public static function withExclusionsFile(?string $path): self
    {
        if (null === $path || !is_file($path)) {
            return new self();
        }
        $exclusions = json_decode((string) file_get_contents($path), true);
        if (!\is_array($exclusions)) {
            throw new \InvalidArgumentException(\sprintf('The exclusions file "%s" does not contain a JSON list.', $path));
        }

        return new self(array_values(array_filter($exclusions, 'is_array')));
    }

    /**
     * @param array<array-key, mixed> $report
     *
     * @return array{score: float, probeCount: int, categories: array<string, float>, fingerprint: string}|null
     */
    public function score(array $report, string $project = ''): ?array
    {
        $probes = $report['probes'] ?? null;
        if (!\is_array($probes) || [] === $probes) {
            return null;
        }
        $categoryScores = [];
        $identities = [];
        $probeCount = 0;
        foreach ($probes as $probe) {
            if (!\is_array($probe) || !\is_string($probe['category'] ?? null) || !\is_array($probe['requests'] ?? null)) {
                continue;
            }
            $category = $probe['category'];
            $expected = array_values(array_diff(
                self::EXPECTED_OVERRIDES[$category] ?? self::DEFAULT_EXPECTED,
                $this->excludedKinds($project, $category, \is_string($probe['value'] ?? null) ? $probe['value'] : ''),
            ));
            $identities[] = \sprintf('%s|%s|%s', $category, \is_string($probe['file'] ?? null) ? $probe['file'] : '', \is_string($probe['value'] ?? null) ? $probe['value'] : '');
            ++$probeCount;
            if ([] === $expected) {
                continue;
            }
            $achieved = 0;
            foreach ($expected as $kind) {
                $request = $probe['requests'][$kind] ?? null;
                if (!\is_array($request) || null !== ($request['error'] ?? null)) {
                    continue;
                }
                $resultCount = $request['resultCount'] ?? 0;
                if (\is_int($resultCount) && 0 < $resultCount) {
                    ++$achieved;
                }
            }
            $categoryScores[$category][] = $achieved / \count($expected);
        }
        if ([] === $categoryScores) {
            return null;
        }
        $categories = [];
        foreach ($categoryScores as $category => $scores) {
            $categories[$category] = array_sum($scores) / \count($scores);
        }
        ksort($categories);
        sort($identities, \SORT_STRING);

        return [
            'score' => array_sum($categories) / \count($categories),
            'probeCount' => $probeCount,
            'categories' => $categories,
            'fingerprint' => substr(hash('sha256', implode("\n", $identities)), 0, 12),
        ];
    }

    /** @return list<string> */
    private function excludedKinds(string $project, string $category, string $value): array
    {
        $kinds = [];
        foreach ($this->exclusions as $exclusion) {
            $exclusionProject = $exclusion['project'] ?? '';
            if (('*' !== $exclusionProject && $exclusionProject !== $project) || ($exclusion['category'] ?? '') !== $category || ($exclusion['value'] ?? '') !== $value) {
                continue;
            }
            $excluded = $exclusion['kinds'] ?? null;
            if (!\is_array($excluded)) {
                return self::DEFAULT_EXPECTED;
            }
            $kinds = [...$kinds, ...array_values(array_filter($excluded, 'is_string'))];
        }

        return $kinds;
    }
}
