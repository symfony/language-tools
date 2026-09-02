<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\CommentParserRegistry;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpAttributeTargetKind;
use Symfony\Lsp\Parser\Php\PhpCapturedReceiverResolver;
use Symfony\Lsp\Parser\Php\PhpClassReference;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class MessengerExtractor
{
    private const AS_MESSAGE_HANDLER = 'Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler';
    private const BUS_TYPES = [
        'Symfony\\Component\\Messenger\\MessageBus',
        'Symfony\\Component\\Messenger\\MessageBusInterface',
    ];

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $parser,
        private readonly PhpCapturedReceiverResolver $capturedReceivers,
        private readonly YamlConfigurationParser $yaml,
        private readonly CommentParserRegistry $comments,
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
            $source = $this->comments->mask('yaml', $document->text);
            foreach ([
                [MessengerSymbolKind::Bus, '/(?:\bbus|default_bus)\s*:\s*["\']?([A-Za-z_][A-Za-z0-9_.-]*)/'],
                [MessengerSymbolKind::Transport, '/(?:fromTransport|from_transport|failure_transport)\s*:\s*["\']?([A-Za-z_][A-Za-z0-9_.-]*)/'],
            ] as [$kind, $pattern]) {
                preg_match_all($pattern, $source, $matches, \PREG_OFFSET_CAPTURE);
                foreach ($matches[1] as [$name, $offset]) {
                    $symbols[] = $this->symbol($kind, $name, $document->uri, $document->text, $offset, false);
                }
            }
        }
        if ('php' === $document->languageId) {
            $php = $this->parser->parse($document->text);
            $source = $this->comments->mask('php', $document->text);
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
                $handles = $this->directClassReference($source, $php, $attribute->argument('handles'));
                if (null !== $handles) {
                    $symbols[] = $this->symbol(MessengerSymbolKind::Message, $handles->className, $document->uri, $document->text, $handles->startOffset, false, $handles->endOffset - $handles->startOffset);
                }
            }
            preg_match_all('/BusNameStamp\s*\(\s*["\']([A-Za-z_][A-Za-z0-9_.-]*)/', $source, $matches, \PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$name, $offset]) {
                $symbols[] = $this->symbol(MessengerSymbolKind::Bus, $name, $document->uri, $document->text, $offset, false);
            }
            $parents = $this->phpParents($source, $php);
            foreach ($php->methodCalls as $call) {
                if ('dispatch' !== $call->method || !array_any($this->capturedReceivers->variables($source, $php, $call), static fn ($variable): bool => [] !== array_intersect(self::BUS_TYPES, $variable->types))) {
                    continue;
                }
                $messageArgument = $call->positionalArgument(0);
                $message = $php->firstObjectCreation($messageArgument);
                if (null !== $message && $message->startOffset === $messageArgument?->expressionStartOffset) {
                    $symbols[] = $this->symbol(MessengerSymbolKind::Message, $message->className, $document->uri, $document->text, $message->classNameStartOffset, false, $message->classNameEndOffset - $message->classNameStartOffset);
                }
            }
            foreach ($php->objectCreations as $envelope) {
                if ('Symfony\\Component\\Messenger\\Envelope' !== $envelope->className) {
                    continue;
                }
                $messageArgument = $envelope->positionalArgument(0);
                $message = $php->firstObjectCreation($messageArgument);
                if (null !== $message && $message->startOffset === $messageArgument?->expressionStartOffset) {
                    $symbols[] = $this->symbol(MessengerSymbolKind::Message, $message->className, $document->uri, $document->text, $message->classNameStartOffset, false, $message->classNameEndOffset - $message->classNameStartOffset);
                }
            }
        }

        return new MessengerSourceFacts($document->uri, $this->unique($symbols), $parents, $handlers);
    }

    private function directClassReference(string $source, PhpDocument $php, ?PhpArgument $argument): ?PhpClassReference
    {
        $reference = $php->soleClassReference($argument);
        $start = $argument?->expressionStartOffset;
        $end = $argument?->expressionEndOffset;
        if (null === $reference || !\is_int($start) || !\is_int($end)) {
            return null;
        }
        $before = trim(substr($source, $start, $reference->startOffset - $start));
        $after = substr($source, $reference->endOffset, $end - $reference->endOffset);

        return '' === $before && 1 === preg_match('/^\s*::\s*class\s*$/iD', $after) ? $reference : null;
    }

    /** @return list<MessengerSourceSymbol> */
    private function yamlSymbols(string $uri, string $text): array
    {
        $symbols = [];
        foreach ($this->yaml->parse($text) as $occurrence) {
            $path = $occurrence->path;
            $parent = \array_slice($path, 0, -1);
            $key = [] === $path ? '' : $path[\count($path) - 1];
            $keyOffset = $this->converter->toByteOffset($text, $occurrence->keyRange->start);
            $declarationKind = match (\array_slice($parent, -3)) {
                ['framework', 'messenger', 'buses'] => MessengerSymbolKind::Bus,
                ['framework', 'messenger', 'transports'] => MessengerSymbolKind::Transport,
                default => null,
            };
            if (null !== $declarationKind) {
                $symbols[] = $this->symbol($declarationKind, $key, $uri, $text, $keyOffset, true);
            }
            if (\array_slice($parent, -3) !== ['framework', 'messenger', 'routing']) {
                continue;
            }
            $symbols[] = $this->symbol(MessengerSymbolKind::Message, ltrim($key, '\\'), $uri, $text, $keyOffset, false, \strlen($key));
            $valueOffset = $this->converter->toByteOffset($text, $occurrence->valueRange->start);
            preg_match_all('/[A-Za-z_][A-Za-z0-9_.-]*/', $occurrence->value, $names, \PREG_OFFSET_CAPTURE);
            foreach ($names[0] as [$name, $relativeOffset]) {
                $symbols[] = $this->symbol(MessengerSymbolKind::Transport, $name, $uri, $text, $valueOffset + $relativeOffset, false);
            }
        }

        return $symbols;
    }

    private function symbol(MessengerSymbolKind $kind, string $name, string $uri, string $text, int $offset, bool $declaration, ?int $length = null): MessengerSourceSymbol
    {
        return new MessengerSourceSymbol($kind, $name, $uri, new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + ($length ?? \strlen($name)))), $declaration);
    }

    /** @return array<string, list<string>> */
    private function phpParents(string $text, PhpDocument $php): array
    {
        $parents = [];
        preg_match_all('/\b(class|interface|enum)\s+([A-Za-z_][A-Za-z0-9_]*)\s*([^{}]*)\{/', $text, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            $className = $php->resolveName($match[2]);
            $typeLists = [];
            if (preg_match('/\bextends\s+([^\s,{]+(?:\s*,\s*[^\s,{]+)*)/', $match[3], $extends)) {
                $typeLists[] = $extends[1];
            }
            if (preg_match('/\bimplements\s+([^\{]+)/', $match[3], $implements)) {
                $typeLists[] = trim($implements[1]);
            }
            $types = [];
            foreach ($typeLists as $typeList) {
                $splitTypes = preg_split('/\s*,\s*/', $typeList);
                if (false !== $splitTypes) {
                    array_push($types, ...$splitTypes);
                }
            }
            $resolved = [];
            foreach ($types as $type) {
                $resolved[] = $php->resolveName($type);
            }
            $parents[$className] = array_values(array_unique($resolved));
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
