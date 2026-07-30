<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class PhpAutowireReferenceExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
    ) {
    }

    /** @return list<DependencyInjectionReference> */
    public function extract(string $uri, string $text): array
    {
        $references = [];
        preg_match_all(
            '/#\[\s*(?:\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*\\\\)?Autowire\s*\((.*?)\)\s*\]/s',
            $text,
            $attributes,
            \PREG_OFFSET_CAPTURE,
        );
        foreach ($attributes[1] as [$arguments, $argumentsOffset]) {
            $namedParameterOffsets = [];
            foreach ([
                'service' => DependencyInjectionSymbolKind::Service,
                'param' => DependencyInjectionSymbolKind::Parameter,
            ] as $argument => $kind) {
                if (!preg_match(
                    '/\b'.preg_quote($argument, '/').'\s*:\s*([\'\"])(.*?)\1/s',
                    $arguments,
                    $match,
                    \PREG_OFFSET_CAPTURE,
                )) {
                    continue;
                }

                $name = $match[2][0];
                $optional = DependencyInjectionSymbolKind::Service === $kind && str_starts_with($name, '?');
                $name = trim($name, '%?');
                if ('' === $name) {
                    continue;
                }

                $offset = $argumentsOffset + $match[2][1] + ($optional || str_starts_with($match[2][0], '%') ? 1 : 0);
                $references[] = new DependencyInjectionReference(
                    $kind,
                    $name,
                    $uri,
                    $this->range($text, $offset, \strlen($name)),
                    $optional,
                );
                if (DependencyInjectionSymbolKind::Parameter === $kind) {
                    $namedParameterOffsets[] = $offset;
                }
            }

            preg_match_all('/%([^%\s]+)%/', $arguments, $parameters, \PREG_OFFSET_CAPTURE);
            foreach ($parameters[1] as [$name, $offset]) {
                if (str_starts_with($name, 'env(') || \in_array($argumentsOffset + $offset, $namedParameterOffsets, true)) {
                    continue;
                }

                $references[] = new DependencyInjectionReference(
                    DependencyInjectionSymbolKind::Parameter,
                    $name,
                    $uri,
                    $this->range($text, $argumentsOffset + $offset, \strlen($name)),
                );
            }
        }

        return $references;
    }

    private function range(string $text, int $offset, int $length): Range
    {
        return new Range(
            $this->positionConverter->toPosition($text, $offset),
            $this->positionConverter->toPosition($text, $offset + $length),
        );
    }
}
