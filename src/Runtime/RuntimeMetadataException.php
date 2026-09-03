<?php

namespace Symfony\Lsp\Runtime;

/**
 * @phpstan-type RuntimeMetadataCause array{class: string, message: string, origin?: string, frames: list<string>}
 * @phpstan-type RuntimeMetadataSectionError array{section: string, chain: non-empty-list<RuntimeMetadataCause>}
 */
class RuntimeMetadataException extends \RuntimeException
{
    /**
     * @param list<string>                      $sections
     * @param list<RuntimeMetadataSectionError> $sectionErrors
     */
    public function __construct(
        public readonly array $sections,
        public readonly array $sectionErrors = [],
    ) {
        parent::__construct(\in_array('runtime', $sections, true)
            ? 'The project bridge could not boot the application kernel.'
            : 'The project bridge could not load runtime metadata'.([] === $sections ? '' : ': '.implode(', ', $sections)).'.');
    }

    /** @return list<string> */
    public function detailLines(): array
    {
        $lines = [];
        foreach ($this->sectionErrors as $sectionError) {
            foreach ($sectionError['chain'] as $index => $cause) {
                $prefix = match (true) {
                    0 !== $index => 'Caused by',
                    'runtime' === $sectionError['section'] => 'Kernel boot',
                    default => \sprintf('Runtime section "%s"', $sectionError['section']),
                };
                $lines[] = \sprintf(
                    '%s: %s%s: %s',
                    $prefix,
                    $cause['class'],
                    isset($cause['origin']) ? ' at '.$cause['origin'] : '',
                    $cause['message'],
                );
                foreach ($cause['frames'] as $frame) {
                    $lines[] = '  at '.$frame;
                }
            }
        }

        return $lines;
    }
}
