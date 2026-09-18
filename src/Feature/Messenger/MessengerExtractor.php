<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpAttributeTargetKind;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpReceiverMatch;
use Symfony\Lsp\Parser\Php\PhpTypeKind;

final class MessengerExtractor
{
    private const AS_MESSAGE_HANDLER = 'Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler';
    private const BUS_NAME_STAMP = 'Symfony\\Component\\Messenger\\Stamp\\BusNameStamp';
    private const BUS_TYPES = [
        'Symfony\\Component\\Messenger\\MessageBus',
        'Symfony\\Component\\Messenger\\MessageBusInterface',
    ];
    private const ROUTING = ['framework', 'messenger', 'routing'];

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $parser,
        private readonly YamlConfigurationParser $yaml,
    ) {
    }

    public function extract(SourceDocument $document): MessengerSourceFacts
    {
        /** @var list<MessengerSourceSymbol> $symbols */
        $symbols = [];
        $parents = [];
        $handlers = [];
        if ('yaml' === $document->languageId) {
            array_push($symbols, ...$this->yamlSymbols($document->uri, $document->text));
        }
        if ('php' === $document->languageId) {
            $php = $this->parser->parse($document->text);
            foreach ($php->attributesNamed(self::AS_MESSAGE_HANDLER) as $attribute) {
                $target = $attribute->targets[0] ?? null;
                if (!\in_array($target?->kind, [PhpAttributeTargetKind::Type, PhpAttributeTargetKind::Method], true)) {
                    continue;
                }
                $handlers[] = substr($document->text, $attribute->startOffset, $attribute->endOffset - $attribute->startOffset);
                foreach ([
                    [MessengerSymbolKind::Bus, 'bus'],
                    [MessengerSymbolKind::Transport, 'fromTransport'],
                ] as [$kind, $argumentName]) {
                    $literal = $attribute->argument($argumentName)?->stringLiteral;
                    if (null === $literal || 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $literal->value)) {
                        continue;
                    }
                    $symbols[] = $this->symbol($kind, $literal->value, $document->uri, $document->text, $literal->startOffset, false, $literal->endOffset - $literal->startOffset);
                }
                $handles = $attribute->argument('handles')?->completeClassReference;
                if (null !== $handles) {
                    $symbols[] = $this->symbol(MessengerSymbolKind::Message, $handles->className, $document->uri, $document->text, $handles->startOffset, false, $handles->endOffset - $handles->startOffset);
                }
            }
            $parents = $this->phpParents($php);
            foreach ($php->methodCalls as $call) {
                if ('dispatch' !== $call->method || PhpReceiverMatch::Matches !== $php->matchReceiver($call, ...self::BUS_TYPES)) {
                    continue;
                }
                $messageArgument = $call->positionalArgument(0);
                $message = $php->firstObjectCreation($messageArgument);
                if (null !== $message && $message->startOffset === $messageArgument?->expressionStartOffset) {
                    $symbols[] = $this->symbol(MessengerSymbolKind::Message, $message->className, $document->uri, $document->text, $message->classNameStartOffset, false, $message->classNameEndOffset - $message->classNameStartOffset);
                }
            }
            foreach ($php->objectCreations as $creation) {
                if (self::BUS_NAME_STAMP === $creation->className) {
                    $literal = $creation->positionalArgument(0)?->stringLiteral;
                    if (null !== $literal && 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $literal->value)) {
                        $symbols[] = $this->symbol(MessengerSymbolKind::Bus, $literal->value, $document->uri, $document->text, $literal->startOffset, false, $literal->endOffset - $literal->startOffset);
                    }
                }
                if ('Symfony\\Component\\Messenger\\Envelope' !== $creation->className) {
                    continue;
                }
                $messageArgument = $creation->positionalArgument(0);
                $message = $php->firstObjectCreation($messageArgument);
                if (null !== $message && $message->startOffset === $messageArgument?->expressionStartOffset) {
                    $symbols[] = $this->symbol(MessengerSymbolKind::Message, $message->className, $document->uri, $document->text, $message->classNameStartOffset, false, $message->classNameEndOffset - $message->classNameStartOffset);
                }
            }
        }

        return new MessengerSourceFacts($document->uri, $this->unique($symbols), $parents, $handlers);
    }

    /** @return list<MessengerSourceSymbol> */
    private function yamlSymbols(string $uri, string $text): array
    {
        $occurrences = $this->yaml->parse($text);
        $containers = [];
        foreach ($occurrences as $occurrence) {
            $containers[$this->identity($occurrence->scope, \array_slice($occurrence->path, 0, -1))] = true;
        }
        $symbols = [];
        foreach ($occurrences as $occurrence) {
            $path = $occurrence->path;
            $parent = \array_slice($path, 0, -1);
            $key = [] === $path ? '' : $path[\count($path) - 1];
            $environment = 'base' === $occurrence->scope ? null : substr($occurrence->scope, \strlen('when@'));
            $keyOffset = $this->converter->toByteOffset($text, $occurrence->keyRange->start);
            $declarationKind = match (\array_slice($parent, -3)) {
                ['framework', 'messenger', 'buses'] => MessengerSymbolKind::Bus,
                ['framework', 'messenger', 'transports'] => MessengerSymbolKind::Transport,
                default => null,
            };
            $routedMessage = self::ROUTING === \array_slice($parent, -3);
            if (null !== $declarationKind) {
                $symbols[] = $this->symbol($declarationKind, $key, $uri, $text, $keyOffset, true, environment: $environment);
            }
            $referenceKind = $this->referenceKind($path, $parent, $key);
            $reference = null === $referenceKind ? null : $this->referenceName($occurrence->value);
            if (null !== $reference) {
                [$name, $nameOffset] = $reference;
                $symbols[] = $this->symbol($referenceKind, $name, $uri, $text, $this->converter->toByteOffset($text, $occurrence->valueRange->start) + $nameOffset, false, environment: $environment);
            }
            if ($routedMessage) {
                $symbols[] = $this->symbol(MessengerSymbolKind::Message, ltrim($key, '\\'), $uri, $text, $keyOffset, false, \strlen($key), $environment);
            }
            $senders = 'senders' === $key
                ? self::ROUTING === \array_slice($parent, -4, 3)
                : $routedMessage && !isset($containers[$this->identity($occurrence->scope, $path)]);
            if (!$senders) {
                continue;
            }
            $valueOffset = $this->converter->toByteOffset($text, $occurrence->valueRange->start);
            preg_match_all('/[A-Za-z_][A-Za-z0-9_.-]*/', $occurrence->value, $names, \PREG_OFFSET_CAPTURE);
            foreach ($names[0] as [$name, $relativeOffset]) {
                $symbols[] = $this->symbol(MessengerSymbolKind::Transport, $name, $uri, $text, $valueOffset + $relativeOffset, false, environment: $environment);
            }
        }

        return $symbols;
    }

    /**
     * @param list<string> $path
     * @param list<string> $parent
     */
    private function referenceKind(array $path, array $parent, string $key): ?MessengerSymbolKind
    {
        if (['framework', 'messenger'] === \array_slice($path, 0, 2)) {
            return match (true) {
                'default_bus' === $key && 2 === \count($parent) => MessengerSymbolKind::Bus,
                'failure_transport' === $key => MessengerSymbolKind::Transport,
                default => null,
            };
        }
        if ('services' !== ($path[0] ?? null) || !\in_array('tags', \array_slice($parent, -2), true)) {
            return null;
        }

        return match ($key) {
            'bus' => MessengerSymbolKind::Bus,
            'from_transport' => MessengerSymbolKind::Transport,
            default => null,
        };
    }

    /** @param list<string> $path */
    private function identity(string $scope, array $path): string
    {
        return $scope."\0".implode("\0", $path);
    }

    /**
     * The referenced name and its byte offset inside the raw value.
     *
     * @return array{string, int}|null
     */
    private function referenceName(string $value): ?array
    {
        $offset = 0;
        if (\strlen($value) > 1 && \in_array($quote = $value[0], ['"', "'"], true) && str_ends_with($value, $quote)) {
            $value = substr($value, 1, -1);
            $offset = 1;
        }
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $value)
            || (0 === $offset && \in_array(strtolower($value), ['null', 'true', 'false'], true))
        ) {
            return null;
        }

        return [$value, $offset];
    }

    private function symbol(MessengerSymbolKind $kind, string $name, string $uri, string $text, int $offset, bool $declaration, ?int $length = null, ?string $environment = null): MessengerSourceSymbol
    {
        return new MessengerSourceSymbol($kind, $name, $uri, new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + ($length ?? \strlen($name)))), $declaration, $environment);
    }

    /** @return array<string, list<string>> */
    private function phpParents(PhpDocument $php): array
    {
        $parents = [];
        foreach ($php->typeDeclarations as $type) {
            if (PhpTypeKind::Trait_ === $type->kind) {
                continue;
            }
            $typeParents = $type->interfaceNames;
            if (null !== $type->parentClassName) {
                array_unshift($typeParents, $type->parentClassName);
            }
            if ([] !== $typeParents) {
                $parents[$type->name] = array_values(array_unique($typeParents));
            }
        }

        return $parents;
    }

    /**
     * @param list<MessengerSourceSymbol> $symbols
     *
     * @return list<MessengerSourceSymbol>
     */
    private function unique(array $symbols): array
    {
        $unique = [];
        foreach ($symbols as $symbol) {
            $key = $symbol->kind->name.'|'.$symbol->range->start->line.'|'.$symbol->range->start->character;
            $unique[$key] = $symbol;
        }

        return array_values($unique);
    }
}
