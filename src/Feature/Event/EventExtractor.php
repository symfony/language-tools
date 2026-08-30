<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Php\PhpAttribute;
use Symfony\Lsp\Parser\Php\PhpAttributeTargetKind;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpTypeDeclaration;

final class EventExtractor
{
    private const AS_EVENT_LISTENER = 'Symfony\\Component\\EventDispatcher\\Attribute\\AsEventListener';
    private const DISPATCHER_TYPES = [
        'Symfony\\Component\\EventDispatcher\\EventDispatcher',
        'Symfony\\Component\\EventDispatcher\\EventDispatcherInterface',
        'Symfony\\Contracts\\EventDispatcher\\EventDispatcherInterface',
    ];

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $parser,
        private readonly PhpCommentParserInterface $phpComments,
    ) {
    }

    public function extract(string $uri, string $languageId, string $text): EventSourceFacts
    {
        if ('php' === $languageId) {
            return $this->extractPhp($uri, $text);
        }
        if ('yaml' === $languageId) {
            return new EventSourceFacts($uri, $this->yamlSymbols($uri, $text));
        }

        return new EventSourceFacts($uri, []);
    }

    public function completionPrefix(string $languageId, string $text, int $offset): ?string
    {
        $before = substr($text, 0, $offset);
        if ('php' === $languageId) {
            $php = $this->parser->parse($text);
            $masked = $this->phpComments->mask($text);
            $before = substr($masked, 0, $offset);
            if (preg_match('/(?:#\[\s*|,\s*)([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*\([^)]*\bevent\s*:\s*["\']([^"\']*)$/s', $before, $match)
                && self::AS_EVENT_LISTENER === $php->resolveName($match[1])
            ) {
                return $match[2];
            }
            preg_match_all('/function\s+getSubscribedEvents\s*\([^)]*\)[^{]*\{/', $masked, $subscriberMethods, \PREG_OFFSET_CAPTURE);
            foreach ($subscriberMethods[0] as [$declaration, $declarationOffset]) {
                $open = $declarationOffset + \strlen($declaration) - 1;
                if ($offset <= $open || $offset > $this->matchingBrace($masked, $open)) {
                    continue;
                }
                $bodyBefore = substr($masked, $open + 1, $offset - $open - 1);
                if (preg_match('/(?:\[|,)\s*["\']([^"\']*)$/s', $bodyBefore, $match)) {
                    return $match[1];
                }
            }
            $dispatchers = $this->eventDispatcherVariables($php);
            if (preg_match('/(?:\$([A-Za-z_][A-Za-z0-9_]*)|\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*))\s*->\s*(?:addListener\s*\(\s*|dispatch\s*\([^,\r\n]+,\s*)["\']([^"\']*)$/', $before, $match)) {
                $variable = '' !== $match[2] ? $match[2] : $match[1];
                if (isset($dispatchers[$variable])) {
                    return $match[3];
                }
            }
        }
        if ('yaml' === $languageId) {
            return $this->yamlCompletionPrefix($before);
        }

        return null;
    }

    private function extractPhp(string $uri, string $text): EventSourceFacts
    {
        $symbols = [];
        $invalidListenerMethods = [];
        $php = $this->parser->parse($text);
        $source = $this->phpComments->mask($text);
        $listeners = [];
        /** @var array<int, array{PhpAttribute, PhpTypeDeclaration|null}> $listenerAttributes */
        $listenerAttributes = [];
        foreach ($php->typeDeclarations as $type) {
            foreach ($php->attributesOn(PhpAttributeTargetKind::Type, $type->name) as $attribute) {
                if (self::AS_EVENT_LISTENER === $attribute->name) {
                    $listenerAttributes[$attribute->startOffset] = [$attribute, $type];
                }
            }
        }
        foreach ($php->methodDeclarations as $method) {
            foreach ($php->attributesOn(PhpAttributeTargetKind::Method, $method->className, $method->name) as $attribute) {
                if (self::AS_EVENT_LISTENER === $attribute->name) {
                    $listenerAttributes[$attribute->startOffset] = [$attribute, null];
                }
            }
        }
        ksort($listenerAttributes);
        foreach ($listenerAttributes as [$attribute, $type]) {
            $listeners[] = substr($text, $attribute->startOffset, $attribute->endOffset - $attribute->startOffset);
            $eventArgument = $attribute->argument('event');
            $event = $eventArgument?->stringLiteral;
            if (null !== $event && '' !== $event->value) {
                $symbols[] = $this->symbol($event->value, $uri, $text, $event->startOffset, true, $event->endOffset - $event->startOffset);
            } elseif (null !== $eventReference = $php->firstClassReference($eventArgument)) {
                $symbols[] = $this->symbol($eventReference->className, $uri, $text, $eventReference->startOffset, true, $eventReference->endOffset - $eventReference->startOffset);
            }
            if (null !== $type) {
                $invalid = $this->invalidListenerMethod($attribute, $type, $php, $source, $text);
                if (null !== $invalid) {
                    $invalidListenerMethods[] = $invalid;
                }
            }
        }

        foreach ($php->methodCalls as $call) {
            if (!array_any($php->receiverVariables($call), static fn ($variable): bool => [] !== array_intersect(self::DISPATCHER_TYPES, $variable->types))) {
                continue;
            }
            if ('dispatch' === $call->method) {
                $eventArgument = $call->positionalArgument(0);
                $event = $php->firstObjectCreation($eventArgument);
                if (null !== $event && $event->startOffset === $eventArgument?->expressionStartOffset) {
                    $symbols[] = $this->symbol($event->className, $uri, $text, $event->classNameStartOffset, false, $event->classNameEndOffset - $event->classNameStartOffset);
                }
                $name = $call->positionalArgument(1)?->stringLiteral;
                if (null !== $name && '' !== $name->value) {
                    $symbols[] = $this->symbol($name->value, $uri, $text, $name->startOffset, false, $name->endOffset - $name->startOffset);
                }
            } elseif ('addListener' === $call->method) {
                $name = $call->positionalArgument(0)?->stringLiteral;
                if (null !== $name && '' !== $name->value) {
                    $symbols[] = $this->symbol($name->value, $uri, $text, $name->startOffset, false, $name->endOffset - $name->startOffset);
                }
            }
        }

        preg_match_all('/function\s+getSubscribedEvents\s*\([^)]*\)[^{]*\{/', $source, $subscriberMethods, \PREG_OFFSET_CAPTURE);
        foreach ($subscriberMethods[0] as [$declaration, $declarationOffset]) {
            $open = $declarationOffset + \strlen($declaration) - 1;
            $close = $this->matchingBrace($source, $open);
            $body = substr($source, $open + 1, $close - $open - 1);
            preg_match_all('/["\']([^"\']+)["\']\s*=>/', $body, $stringEvents, \PREG_OFFSET_CAPTURE);
            foreach ($stringEvents[1] as [$name, $offset]) {
                $symbols[] = $this->symbol($name, $uri, $text, $open + 1 + $offset, true);
            }
            preg_match_all('/([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::class\s*=>/', $body, $classEvents, \PREG_OFFSET_CAPTURE);
            foreach ($classEvents[1] as [$name, $offset]) {
                $symbols[] = $this->symbol($php->resolveName($name), $uri, $text, $open + 1 + $offset, true, \strlen($name));
            }
        }

        return new EventSourceFacts($uri, $this->unique($symbols), $invalidListenerMethods, $listeners);
    }

    private function yamlCompletionPrefix(string $text): ?string
    {
        $listenerIndent = null;
        $lines = preg_split('/\R/', $text);
        if (false === $lines) {
            return null;
        }
        $lastLine = array_key_last($lines);
        foreach ($lines as $index => $line) {
            if (preg_match('/^(\s*)-\s*(?:\{\s*)?name\s*:\s*["\']?kernel\.event_listener["\']?(.*)$/', $line, $tag)) {
                $listenerIndent = \strlen($tag[1]);
                if ($index === $lastLine && preg_match('/\bevent\s*:\s*["\']?([A-Za-z0-9_.\\\\-]*)$/', $tag[2], $event)) {
                    return $event[1];
                }
                continue;
            }
            if (null === $listenerIndent || !preg_match('/^(\s*)/', $line, $indentMatch)) {
                continue;
            }
            $indent = \strlen($indentMatch[1]);
            if ('' !== trim($line) && $indent <= $listenerIndent) {
                $listenerIndent = null;
                continue;
            }
            if ($index === $lastLine && preg_match('/^\s*event\s*:\s*["\']?([A-Za-z0-9_.\\\\-]*)$/', $line, $event)) {
                return $event[1];
            }
        }

        return null;
    }

    /** @return list<EventSourceSymbol> */
    private function yamlSymbols(string $uri, string $text): array
    {
        $symbols = [];
        $listenerIndent = null;
        preg_match_all('/^.*(?:\R|$)/m', $text, $lines, \PREG_OFFSET_CAPTURE);
        foreach ($lines[0] as [$line, $lineOffset]) {
            $line = rtrim($line, "\r\n");
            if (preg_match('/^(\s*)-\s*(?:\{\s*)?name\s*:\s*["\']?kernel\.event_listener["\']?(.*)$/', $line, $tag)) {
                $listenerIndent = \strlen($tag[1]);
                if (preg_match('/\bevent\s*:\s*["\']?([A-Za-z0-9_.\\\\-]+)/', $tag[2], $event, \PREG_OFFSET_CAPTURE)) {
                    $offset = $lineOffset + (int) strpos($line, $tag[2]) + $event[1][1];
                    $symbols[] = $this->symbol($event[1][0], $uri, $text, $offset, true);
                }
                continue;
            }
            if (null === $listenerIndent || !preg_match('/^(\s*)/', $line, $indentMatch)) {
                continue;
            }
            $indent = \strlen($indentMatch[1]);
            if ('' !== trim($line) && $indent <= $listenerIndent) {
                $listenerIndent = null;
                continue;
            }
            if (preg_match('/^\s*event\s*:\s*["\']?([A-Za-z0-9_.\\\\-]+)/', $line, $event, \PREG_OFFSET_CAPTURE)) {
                $symbols[] = $this->symbol($event[1][0], $uri, $text, $lineOffset + $event[1][1], true);
            }
        }

        return $symbols;
    }

    private function symbol(string $name, string $uri, string $text, int $offset, bool $declaration, ?int $length = null): EventSourceSymbol
    {
        return new EventSourceSymbol(ltrim($name, '\\'), $uri, new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + ($length ?? \strlen($name)))), $declaration);
    }

    private function invalidListenerMethod(PhpAttribute $attribute, PhpTypeDeclaration $type, PhpDocument $php, string $source, string $text): ?InvalidEventListenerMethod
    {
        $method = $attribute->argument('method')?->stringLiteral;
        if (null === $method || '' === $method->value) {
            return null;
        }
        if (!$type->isClass() || null !== $type->parentClassName || str_contains($type->signature, 'extends')) {
            return null;
        }
        $body = substr($source, $type->startOffset, $type->endOffset - $type->startOffset);
        if (preg_match('/\buse\s+[^;]+;/', $body)) {
            return null;
        }
        foreach ($php->methodDeclarations as $declaration) {
            if ($type->name === $declaration->className && $method->value === $declaration->name) {
                return null;
            }
        }

        return new InvalidEventListenerMethod(
            $type->name,
            $method->value,
            new Range($this->converter->toPosition($text, $method->startOffset), $this->converter->toPosition($text, $method->endOffset)),
        );
    }

    /** @return array<string, true> */
    private function eventDispatcherVariables(PhpDocument $php): array
    {
        $variables = [];
        foreach ($php->typedVariables as $variable) {
            if ([] !== array_intersect(self::DISPATCHER_TYPES, $variable->types)) {
                $variables[$variable->name] = true;
            }
        }

        return $variables;
    }

    private function matchingBrace(string $text, int $open): int
    {
        $depth = 0;
        for ($offset = $open, $length = \strlen($text); $offset < $length; ++$offset) {
            if ('{' === $text[$offset]) {
                ++$depth;
            } elseif ('}' === $text[$offset] && 0 === --$depth) {
                return $offset;
            }
        }

        return \strlen($text);
    }

    /**
     * @param list<EventSourceSymbol> $symbols
     *
     * @return list<EventSourceSymbol>
     */
    private function unique(array $symbols): array
    {
        $unique = [];
        foreach ($symbols as $symbol) {
            $key = $symbol->name.'|'.$symbol->range->start->line.'|'.$symbol->range->start->character;
            $unique[$key] = $symbol;
        }

        return array_values($unique);
    }
}
