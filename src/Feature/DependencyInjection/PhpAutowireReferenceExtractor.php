<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class PhpAutowireReferenceExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly PhpParserInterface $parser,
    ) {
    }

    /** @return list<DependencyInjectionReference> */
    public function extract(string $uri, string $text): array
    {
        $references = [];
        foreach ($this->parser->parse($text)->attributes() as $attribute) {
            if ('Symfony\Component\DependencyInjection\Attribute\Autowire' !== $attribute->name()) {
                continue;
            }

            $namedParameterOffsets = [];
            foreach ([
                'service' => DependencyInjectionSymbolKind::Service,
                'param' => DependencyInjectionSymbolKind::Parameter,
            ] as $argument => $kind) {
                $literal = $attribute->argument($argument)?->stringLiteral();
                if (null === $literal) {
                    continue;
                }

                $rawName = $literal->value();
                $optional = DependencyInjectionSymbolKind::Service === $kind && str_starts_with($rawName, '?');
                $name = trim($rawName, '%?');
                if ('' === $name) {
                    continue;
                }

                $offset = $literal->startOffset() + ($optional || str_starts_with($rawName, '%') ? 1 : 0);
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

            foreach ($attribute->arguments() as $argument) {
                $literal = $argument->stringLiteral();
                if (null === $literal) {
                    continue;
                }

                preg_match_all('/%([^%\s]+)%/', $literal->value(), $parameters, \PREG_OFFSET_CAPTURE);
                foreach ($parameters[1] as [$name, $offset]) {
                    $offset += $literal->startOffset();
                    if (str_starts_with($name, 'env(') || \in_array($offset, $namedParameterOffsets, true)) {
                        continue;
                    }

                    $references[] = new DependencyInjectionReference(
                        DependencyInjectionSymbolKind::Parameter,
                        $name,
                        $uri,
                        $this->range($text, $offset, \strlen($name)),
                    );
                }
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
