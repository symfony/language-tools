<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;

final class SerializerMetadataExtractor
{
    private const GROUP_ATTRIBUTES = [
        'Symfony\\Component\\Serializer\\Attribute\\Groups',
        'Symfony\\Component\\Serializer\\Annotation\\Groups',
    ];
    private const CONTEXT_ATTRIBUTES = [
        'Symfony\\Component\\Serializer\\Attribute\\Context',
        'Symfony\\Component\\Serializer\\Annotation\\Context',
    ];
    private const CONTEXT_METHODS = ['serialize', 'deserialize', 'normalize', 'denormalize'];
    private const SERIALIZERS = [
        'Symfony\\Component\\Serializer\\SerializerInterface',
        'Symfony\\Component\\Serializer\\Serializer',
        'Symfony\\Component\\Serializer\\Normalizer\\NormalizerInterface',
        'Symfony\\Component\\Serializer\\Normalizer\\DenormalizerInterface',
    ];

    public function __construct(
        private readonly PositionConverter $converter,
    ) {
    }

    /** @return list<MetadataSourceSymbol> */
    public function symbols(string $uri, string $text, string $source, PhpDocument $php): array
    {
        $symbols = [];
        foreach ($php->attributes as $attribute) {
            $declaration = \in_array($attribute->name, self::GROUP_ATTRIBUTES, true);
            if (!$declaration && !\in_array($attribute->name, self::CONTEXT_ATTRIBUTES, true)) {
                continue;
            }
            foreach ($attribute->arguments as $argument) {
                $expression = $argument->expression;
                $offset = $argument->expressionStartOffset;
                if (!\is_string($expression) || !\is_int($offset)) {
                    continue;
                }
                array_push($symbols, ...$declaration
                    ? $this->quotedSymbols($uri, $text, $expression, $offset, true)
                    : $this->contextSymbols($uri, $text, $expression, $offset));
            }
        }
        foreach ($php->methodCalls as $call) {
            if (!$this->passesSerializerContext($call, $php)) {
                continue;
            }
            foreach ($call->arguments as $argument) {
                if (\is_string($argument->expression) && \is_int($argument->expressionStartOffset)) {
                    array_push($symbols, ...$this->contextSymbols($uri, $text, $argument->expression, $argument->expressionStartOffset));
                }
            }
        }

        return $symbols;
    }

    public function completionContext(string $text, string $source, PhpDocument $php, int $offset): ?MetadataCompletionContext
    {
        $before = substr($source, 0, $offset);
        if (preg_match('/["\']groups["\']\s*=>\s*\[[^\]]*["\']([A-Za-z_][A-Za-z0-9_.:-]*)$/s', $before, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context($match[1][0], $text, $match[1][1]);
        }
        if (preg_match('/(?:#\[\s*|,\s*)([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*\([^)]*["\']([A-Za-z_][A-Za-z0-9_.:-]*)$/s', $before, $match, \PREG_OFFSET_CAPTURE)
            && \in_array($php->resolveName($match[1][0]), self::GROUP_ATTRIBUTES, true)
        ) {
            return $this->context($match[2][0], $text, $match[2][1]);
        }

        return null;
    }

    /**
     * Whether the call takes a serializer context array: a serializer method, or
     * a controller's `json()` helper.
     */
    private function passesSerializerContext(PhpMethodCall $call, PhpDocument $php): bool
    {
        if (PhpMethodReceiverKind::This === $call->receiverContext->kind) {
            return 'json' === $call->method || \in_array($call->method, self::CONTEXT_METHODS, true);
        }

        return \in_array($call->method, self::CONTEXT_METHODS, true)
            && ([] === $php->receiverVariables($call) || $php->receiverHasType($call, ...self::SERIALIZERS));
    }

    /**
     * Group names of every `groups` entry of a literal context array.
     *
     * @return list<MetadataSourceSymbol>
     */
    private function contextSymbols(string $uri, string $text, string $expression, int $base): array
    {
        preg_match_all('/["\']groups["\']\s*=>\s*\[(.*?)\]/s', $expression, $groups, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        $symbols = [];
        foreach ($groups as $group) {
            array_push($symbols, ...$this->quotedSymbols($uri, $text, $group[1][0], $base + $group[1][1], false));
        }

        return $symbols;
    }

    /** @return list<MetadataSourceSymbol> */
    private function quotedSymbols(string $uri, string $text, string $fragment, int $base, bool $declaration): array
    {
        preg_match_all('/["\']([A-Za-z_][A-Za-z0-9_.:-]*)["\']/', $fragment, $matches, \PREG_OFFSET_CAPTURE);
        $symbols = [];
        foreach ($matches[1] as [$name, $offset]) {
            $symbols[] = new MetadataSourceSymbol(MetadataSymbolKind::SerializerGroup, $name, $uri, $this->converter->toRange($text, $base + $offset, \strlen($name)), $declaration);
        }

        return $symbols;
    }

    private function context(string $prefix, string $text, int $offset): MetadataCompletionContext
    {
        return new MetadataCompletionContext(MetadataCompletionKind::SerializerGroup, $prefix, $this->converter->toRange($text, $offset, \strlen($prefix)));
    }
}
