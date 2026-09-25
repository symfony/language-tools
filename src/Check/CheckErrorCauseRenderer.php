<?php

namespace Symfony\Lsp\Check;

/** @phpstan-import-type CheckErrorCause from CheckResult */
final class CheckErrorCauseRenderer
{
    /**
     * @param CheckErrorCause $cause
     *
     * @return non-empty-list<string>
     */
    public function lines(array $cause, string $indent = ''): array
    {
        $lines = [$indent.\sprintf('Cause: %s: %s', $cause['class'], $cause['message'])];
        foreach ($cause['sections'] ?? [] as $sectionError) {
            foreach ($sectionError['chain'] as $index => $link) {
                $lines[] = $indent.\sprintf(
                    '%s: %s%s: %s',
                    match (true) {
                        0 !== $index => 'Caused by',
                        'runtime' === $sectionError['section'] => 'Kernel boot',
                        default => \sprintf('Runtime section "%s"', $sectionError['section']),
                    },
                    $link['class'],
                    isset($link['origin']) ? ' at '.$link['origin'] : '',
                    $link['message'],
                );
                foreach ($link['frames'] as $frame) {
                    $lines[] = $indent.'  at '.$frame;
                }
            }
        }

        return $lines;
    }
}
