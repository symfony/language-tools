<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\SourceDocument;
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
        private readonly EventYamlListenerAnalyzer $yamlListenerAnalyzer,
        private readonly EventSubscriberMapAnalyzer $subscriberMapAnalyzer,
    ) {
    }

    public function extract(SourceDocument $document): EventSourceFacts
    {
        if ('php' === $document->languageId) {
            return $this->extractPhp($document->uri, $document->text);
        }
        if ('yaml' === $document->languageId) {
            return new EventSourceFacts($document->uri, $this->yamlListenerAnalyzer->symbols($document->uri, $document->text));
        }

        return new EventSourceFacts($document->uri, []);
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
            if (null !== $prefix = $this->subscriberMapAnalyzer->completionPrefix($masked, $offset)) {
                return $prefix;
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
            return $this->yamlListenerAnalyzer->completionPrefix($before);
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
        $types = [];
        foreach ($php->typeDeclarations as $type) {
            $types[$type->name] = $type;
        }
        foreach ($php->attributesNamed(self::AS_EVENT_LISTENER) as $attribute) {
            $target = $attribute->targets[0] ?? null;
            if (PhpAttributeTargetKind::Type === $target?->kind) {
                $type = $types[$target->className] ?? null;
                if (null === $type) {
                    continue;
                }
            } elseif (PhpAttributeTargetKind::Method === $target?->kind) {
                $type = null;
            } else {
                continue;
            }
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

        array_push($symbols, ...$this->subscriberMapAnalyzer->symbols($uri, $text, $source, $php));

        return new EventSourceFacts($uri, $this->unique($symbols), $invalidListenerMethods, $listeners);
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
