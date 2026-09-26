<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;

final class PhpAutowireReferenceExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly PhpParserInterface $parser,
        private readonly ParameterExpressionScanner $parameterExpressions,
    ) {
    }

    /** @return list<DependencyInjectionReference> */
    public function extract(string $uri, string $text): array
    {
        $references = [];
        foreach ($this->parser->parse($text)->attributes as $attribute) {
            if ('Symfony\Component\DependencyInjection\Attribute\Autowire' !== $attribute->name) {
                continue;
            }

            $namedParameterOffsets = [];
            foreach ([
                'service' => DependencyInjectionSymbolKind::Service,
                'param' => DependencyInjectionSymbolKind::Parameter,
            ] as $argument => $kind) {
                $literal = $attribute->argument($argument)?->stringLiteral;
                if (null === $literal) {
                    continue;
                }

                $rawName = $this->raw($text, $literal->startOffset, $literal->endOffset);
                $optional = DependencyInjectionSymbolKind::Service === $kind && str_starts_with($rawName, '?');
                $rawTrimmed = trim($rawName, '%?');
                $name = PhpStringLiteralDecoder::decode($text[$literal->startOffset - 1], $rawTrimmed);
                if ('' === $name) {
                    continue;
                }

                $offset = $literal->startOffset + ($optional || str_starts_with($rawName, '%') ? 1 : 0);
                $references[] = new DependencyInjectionReference(
                    $kind,
                    $name,
                    $uri,
                    $this->positionConverter->toRange($text, $offset, \strlen($rawTrimmed)),
                    $optional,
                );
                if (DependencyInjectionSymbolKind::Parameter === $kind) {
                    $namedParameterOffsets[] = $offset;
                }
            }

            foreach ($attribute->arguments as $argument) {
                $literal = $argument->stringLiteral;
                if (null === $literal) {
                    continue;
                }

                $raw = $this->raw($text, $literal->startOffset, $literal->endOffset);
                foreach ($this->parameterExpressions->scan($raw, $literal->startOffset) as $parameter) {
                    if (str_starts_with($parameter->name, 'env(') || \in_array($parameter->nameStartOffset, $namedParameterOffsets, true)) {
                        continue;
                    }

                    $references[] = new DependencyInjectionReference(
                        DependencyInjectionSymbolKind::Parameter,
                        PhpStringLiteralDecoder::decode($text[$literal->startOffset - 1], $parameter->name),
                        $uri,
                        $this->positionConverter->toRange($text, $parameter->nameStartOffset, \strlen($parameter->name)),
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
}
