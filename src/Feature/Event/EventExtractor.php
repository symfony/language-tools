<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpTypedVariableKind;

final class EventExtractor
{
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
            if (preg_match('/AsEventListener\s*\([^)]*\bevent\s*:\s*["\']([^"\']*)$/s', $before, $match)) {
                return $match[1];
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
        preg_match_all('/#\[\s*(?:[\\\\A-Za-z_][\\\\A-Za-z0-9_]*\\\\)*AsEventListener\b(?:\([^)]*\))?\s*\]/s', $source, $listenerAttributes);
        $listeners = $listenerAttributes[0];

        preg_match_all('/AsEventListener\s*\(([^)]*)\)/s', $source, $attributes, \PREG_OFFSET_CAPTURE);
        foreach ($attributes[1] as [$arguments, $argumentsOffset]) {
            if (preg_match('/\bevent\s*:\s*["\']([^"\']+)/', $arguments, $match, \PREG_OFFSET_CAPTURE)) {
                $symbols[] = $this->symbol($match[1][0], $uri, $text, $argumentsOffset + $match[1][1], true);
            } elseif (preg_match('/\bevent\s*:\s*([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::class/', $arguments, $match, \PREG_OFFSET_CAPTURE)) {
                $symbols[] = $this->symbol($php->resolveName($match[1][0]), $uri, $text, $argumentsOffset + $match[1][1], true, \strlen($match[1][0]));
            }
        }

        foreach ($php->methodCalls() as $call) {
            if (!$this->hasDispatcherReceiver($call, $php)) {
                continue;
            }
            if ('dispatch' === $call->method()) {
                $event = $this->newClassArgument($call->argument(0));
                if (null !== $event) {
                    $symbols[] = $this->symbol($php->resolveName($event['name']), $uri, $text, $event['offset'], false, \strlen($event['name']));
                }
                $name = $call->argument(1)?->stringLiteral();
                if (null !== $name && '' !== $name->value()) {
                    $symbols[] = $this->symbol($name->value(), $uri, $text, $name->startOffset(), false, $name->endOffset() - $name->startOffset());
                }
            } elseif ('addListener' === $call->method()) {
                $name = $call->argument(0)?->stringLiteral();
                if (null !== $name && '' !== $name->value()) {
                    $symbols[] = $this->symbol($name->value(), $uri, $text, $name->startOffset(), false, $name->endOffset() - $name->startOffset());
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

        preg_match_all('/#\[\s*(?:[\\\\A-Za-z_][\\\\A-Za-z0-9_]*\\\\)*AsEventListener\s*\((.*?)\)\s*\]\s*(?:(?:final|abstract|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)([^\{]*)\{/s', $source, $classListeners, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($classListeners as $listener) {
            if (!preg_match('/\bmethod\s*:\s*["\']([^"\']+)["\']/', $listener[1][0], $method, \PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $open = $listener[0][1] + \strlen($listener[0][0]) - 1;
            $close = $this->matchingBrace($source, $open);
            $body = substr($source, $open + 1, $close - $open - 1);
            if (!str_contains($listener[3][0], 'extends') && !preg_match('/\buse\s+[^;]+;/', $body) && !preg_match('/\bfunction\s+'.preg_quote($method[1][0], '/').'\s*\(/', $body)) {
                $className = $php->resolveName($listener[2][0]);
                $methodOffset = $listener[1][1] + $method[1][1];
                $invalidListenerMethods[] = new InvalidEventListenerMethod($className, $method[1][0], new Range($this->converter->toPosition($text, $methodOffset), $this->converter->toPosition($text, $methodOffset + \strlen($method[1][0]))));
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

    /** @return array<string, true> */
    private function eventDispatcherVariables(PhpDocument $php): array
    {
        $variables = [];
        foreach ($php->typedVariables() as $variable) {
            if ([] !== array_intersect(self::DISPATCHER_TYPES, $variable->types())) {
                $variables[$variable->name()] = true;
            }
        }

        return $variables;
    }

    private function hasDispatcherReceiver(PhpMethodCall $call, PhpDocument $php): bool
    {
        $receiver = $call->receiverContext();
        if (null === $receiver->name()) {
            return false;
        }
        foreach ($php->typedVariables() as $variable) {
            if ($receiver->name() !== $variable->name() || [] === array_intersect(self::DISPATCHER_TYPES, $variable->types())) {
                continue;
            }
            if (PhpMethodReceiverKind::Variable === $receiver->kind()
                && \in_array($variable->kind(), [PhpTypedVariableKind::Parameter, PhpTypedVariableKind::PromotedProperty], true)
                && $call->scopeStartOffset() === $variable->scopeStartOffset()
            ) {
                return true;
            }
            if (PhpMethodReceiverKind::ThisProperty === $receiver->kind()
                && \in_array($variable->kind(), [PhpTypedVariableKind::Property, PhpTypedVariableKind::PromotedProperty], true)
                && $call->className() === $variable->className()
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return array{name: string, offset: int}|null */
    private function newClassArgument(?PhpArgument $argument): ?array
    {
        $expression = $argument?->expression();
        $offset = $argument?->expressionStartOffset();
        if (!\is_string($expression) || !\is_int($offset) || 1 !== preg_match('/^\s*new\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)/', $expression, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return ['name' => $match[1][0], 'offset' => $offset + $match[1][1]];
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
            $key = $symbol->name().'|'.$symbol->range()->start()->line().'|'.$symbol->range()->start()->character();
            $unique[$key] = $symbol;
        }

        return array_values($unique);
    }
}
