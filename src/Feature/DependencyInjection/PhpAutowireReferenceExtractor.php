<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;

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

                $rawName = $this->raw($text, $literal->startOffset(), $literal->endOffset());
                $optional = DependencyInjectionSymbolKind::Service === $kind && str_starts_with($rawName, '?');
                $rawTrimmed = trim($rawName, '%?');
                $name = PhpStringLiteralDecoder::decode($text[$literal->startOffset() - 1], $rawTrimmed);
                if ('' === $name) {
                    continue;
                }

                $offset = $literal->startOffset() + ($optional || str_starts_with($rawName, '%') ? 1 : 0);
                $references[] = new DependencyInjectionReference(
                    $kind,
                    $name,
                    $uri,
                    $this->range($text, $offset, \strlen($rawTrimmed)),
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

                preg_match_all('/%([^%\s]+)%/', $this->raw($text, $literal->startOffset(), $literal->endOffset()), $parameters, \PREG_OFFSET_CAPTURE);
                foreach ($parameters[1] as [$rawParameter, $offset]) {
                    $offset += $literal->startOffset();
                    if (str_starts_with($rawParameter, 'env(') || \in_array($offset, $namedParameterOffsets, true)) {
                        continue;
                    }

                    $references[] = new DependencyInjectionReference(
                        DependencyInjectionSymbolKind::Parameter,
                        PhpStringLiteralDecoder::decode($text[$literal->startOffset() - 1], $rawParameter),
                        $uri,
                        $this->range($text, $offset, \strlen($rawParameter)),
                    );
                }
            }
        }

        return $references;
    }

    private function raw(string $text, int $startOffset, int $endOffset): string
    {
        return substr($text, $startOffset, $endOffset - $startOffset);
    }

    private function range(string $text, int $offset, int $length): Range
    {
        return new Range(
            $this->positionConverter->toPosition($text, $offset),
            $this->positionConverter->toPosition($text, $offset + $length),
        );
    }
}
