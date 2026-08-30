<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpAttributeTargetKind;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpExpressionParser;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;
use Symfony\Lsp\Parser\Php\PhpTypeDeclaration;
use Symfony\Lsp\Parser\Php\PhpTypeKind;

final class ConsoleExtractor
{
    private const ARGUMENT_ATTRIBUTE = 'Symfony\\Component\\Console\\Attribute\\Argument';
    private const AS_COMMAND_ATTRIBUTE = 'Symfony\\Component\\Console\\Attribute\\AsCommand';
    private const COMMAND = 'Symfony\\Component\\Console\\Command\\Command';
    private const INPUT_INTERFACE = 'Symfony\\Component\\Console\\Input\\InputInterface';
    private const OPTION_ATTRIBUTE = 'Symfony\\Component\\Console\\Attribute\\Option';

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $parser,
        private readonly PhpExpressionParser $expressionParser,
        private readonly PhpCommentParserInterface $phpComments,
        private readonly BalancedDelimiterMatcher $delimiters,
    ) {
    }

    public function extract(string $uri, string $languageId, string $text): ConsoleSourceFacts
    {
        if ('php' !== $languageId) {
            return new ConsoleSourceFacts($uri, [], []);
        }

        $masked = $this->phpComments->mask($text);
        $php = $this->parser->parse($text);
        $declarations = [];
        foreach ($php->typeDeclarations as $type) {
            if (!\in_array($type->kind, [PhpTypeKind::Class_, PhpTypeKind::Trait_], true)) {
                continue;
            }
            $declarations[] = $this->declaration($masked, $php, $type);
        }

        $references = [];
        foreach ($php->methodCalls as $call) {
            if (!\in_array($call->method, ['getArgument', 'getOption'], true) || !$this->hasInputReceiver($call, $php)) {
                continue;
            }
            $name = $call->positionalArgument(0)?->stringLiteral;
            $className = $call->className;
            if (null === $name || null === $className) {
                continue;
            }
            $references[] = new ConsoleInputReference(
                'getArgument' === $call->method ? ConsoleInputKind::Argument : ConsoleInputKind::Option,
                $name->value,
                $uri,
                new Range($this->converter->toPosition($text, $name->startOffset), $this->converter->toPosition($text, $name->endOffset)),
                $className,
            );
        }

        return new ConsoleSourceFacts($uri, $declarations, $references);
    }

    public function completionContext(string $languageId, string $text, int $offset): ?ConsoleCompletionContext
    {
        if ('php' !== $languageId) {
            return null;
        }
        $masked = $this->phpComments->mask($text);
        $before = substr($masked, 0, $offset);
        if (!preg_match('/(?:\$([A-Za-z_][A-Za-z0-9_]*)|\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*))\s*->\s*(getArgument|getOption)\s*\(\s*([\'\"])(?<prefix>(?:\\\\.|(?!\4).)*)$/s', $before, $match, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        $php = $this->parser->parse($text);
        $methodOffset = $match[3][1];
        $property = \is_string($match[2][0] ?? null);
        $receiver = $property ? $match[2][0] : ($match[1][0] ?? null);
        $receiverKind = $property ? PhpMethodReceiverKind::ThisProperty : PhpMethodReceiverKind::Variable;
        $call = \is_string($receiver) ? array_find($php->methodCalls, static fn (PhpMethodCall $call): bool => $match[3][0] === $call->method && $receiver === $call->receiverContext->name && $receiverKind === $call->receiverContext->kind && $methodOffset >= $call->startOffset && $methodOffset < $call->endOffset) : null;
        if (null === $call || null === $call->className || !$this->hasInputReceiver($call, $php)) {
            return null;
        }
        $rawPrefix = $match['prefix'][0];
        $prefixOffset = $match['prefix'][1];
        if (!\is_string($rawPrefix)) {
            return null;
        }
        $quote = $match[4][0];
        if (!\is_string($quote) || ('"' === $quote && str_contains($rawPrefix, '$'))) {
            return null;
        }
        $prefix = PhpStringLiteralDecoder::decode($quote, $rawPrefix);

        return new ConsoleCompletionContext(
            'getArgument' === $match[3][0] ? ConsoleInputKind::Argument : ConsoleInputKind::Option,
            $prefix,
            new Range($this->converter->toPosition($text, $prefixOffset), $this->converter->toPosition($text, $offset)),
            $call->className,
        );
    }

    private function declaration(string $text, PhpDocument $php, PhpTypeDeclaration $type): ConsoleCommandDeclaration
    {
        $arguments = [];
        $options = [];
        $complete = true;
        $configureRanges = $this->methodBodyRanges($text, $type, 'configure');
        foreach ($php->methodCalls as $call) {
            $receiver = substr($text, $call->receiverContext->startOffset, $call->receiverContext->endOffset - $call->receiverContext->startOffset);
            if ($type->name !== $call->className || 'configure' !== $call->enclosingMethod || !$this->isDefinitionReceiver($receiver)) {
                continue;
            }
            if ('addArgument' === $call->method || 'addOption' === $call->method) {
                $name = $call->positionalArgument(0)?->stringLiteral?->value;
                if (null === $name) {
                    $complete = false;
                    continue;
                }
                if ('addArgument' === $call->method) {
                    $arguments[] = $name;
                } else {
                    $options[] = $name;
                }
                continue;
            }
            if ('setDefinition' !== $call->method) {
                continue;
            }
            $expression = $call->positionalArgument(0)?->expression;
            if (null === $expression) {
                $complete = false;
                continue;
            }
            [$definitionArguments, $definitionOptions, $definitionComplete] = $this->setDefinition($expression);
            $arguments = [...$arguments, ...$definitionArguments];
            $options = [...$options, ...$definitionOptions];
            $complete = $complete && $definitionComplete;
        }
        foreach ($configureRanges as $range) {
            if (!$range['closed']) {
                $complete = false;
            }
        }

        $command = array_any($php->attributesOn(PhpAttributeTargetKind::Type, $type->name), static fn ($attribute): bool => self::AS_COMMAND_ATTRIBUTE === $attribute->name)
            || 0 === strcasecmp(self::COMMAND, (string) $type->parentClassName);
        [$attributeArguments, $attributeOptions, $attributesComplete] = $this->invokableAttributes($text, $php, $type);
        $arguments = [...$arguments, ...$attributeArguments];
        $options = [...$options, ...$attributeOptions];
        $complete = $complete && $attributesComplete;

        $arguments = array_values(array_unique($arguments));
        $options = array_values(array_unique($options));
        sort($arguments);
        sort($options);

        return new ConsoleCommandDeclaration(
            $type->name,
            $type->parentClassName,
            $this->traits($text, $php, $type),
            $arguments,
            $options,
            $command,
            $complete,
        );
    }

    private function isDefinitionReceiver(string $receiver): bool
    {
        $receiver = preg_replace('/\s+/', '', $receiver);

        return '$this' === $receiver
            || (\is_string($receiver) && 1 === preg_match('/^\$this->(?:addArgument|addOption|setDefinition)\s*\(/', $receiver));
    }

    /** @return array{list<string>, list<string>, bool} */
    private function setDefinition(string $expression): array
    {
        $document = $this->expressionParser->parse($expression);
        $arguments = [];
        $options = [];
        $complete = 1 !== preg_match('/\$|\.\.\./', $expression);
        $recognized = false;
        foreach ($document->objectCreations as $creation) {
            $shortName = substr($creation->className, (int) strrpos('\\'.$creation->className, '\\'));
            if ('InputDefinition' === $shortName) {
                $recognized = true;
                continue;
            }
            if (!\in_array($shortName, ['InputArgument', 'InputOption'], true)) {
                $complete = false;
                continue;
            }
            $recognized = true;
            $name = $creation->positionalArgument(0)?->stringLiteral?->value;
            if (null === $name) {
                $complete = false;
                continue;
            }
            if ('InputArgument' === $shortName) {
                $arguments[] = $name;
            } else {
                $options[] = $name;
            }
        }
        if (!$recognized && 1 !== preg_match('/^\s*\[.*\]\s*$/s', $expression)) {
            $complete = false;
        }
        if (preg_match('/(?<!new\s)\b[A-Za-z_][A-Za-z0-9_]*\s*\(/', $expression)) {
            $complete = false;
        }

        return [array_values(array_unique($arguments)), array_values(array_unique($options)), $complete];
    }

    /** @return array{list<string>, list<string>, bool} */
    private function invokableAttributes(string $text, PhpDocument $php, PhpTypeDeclaration $type): array
    {
        $range = $this->methodParameterRange($text, $type, '__invoke');
        if (null === $range) {
            return [[], [], true];
        }
        $parameters = substr($text, $range[0], $range[1] - $range[0]);
        preg_match_all('/#\[\s*(?<attribute>[\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\b(?<arguments>\s*\((?:[^()\'\"]+|\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")*\))?\s*\]\s*(?:(?:public|protected|private|readonly|static)\s+)*(?:[?\\\\A-Za-z_][\\\\A-Za-z0-9_|&?()]*\s+)?\$(?<parameter>[A-Za-z_][A-Za-z0-9_]*)/s', $parameters, $matches, \PREG_SET_ORDER);
        $arguments = [];
        $options = [];
        $complete = true;
        foreach ($matches as $match) {
            $attribute = $php->resolveName($match['attribute']);
            $kind = match ($attribute) {
                self::ARGUMENT_ATTRIBUTE => ConsoleInputKind::Argument,
                self::OPTION_ATTRIBUTE => ConsoleInputKind::Option,
                default => null,
            };
            if (null === $kind) {
                continue;
            }
            $name = $this->attributeInputName($match['arguments'], $match['parameter']);
            if (null === $name) {
                $complete = false;
                continue;
            }
            if (ConsoleInputKind::Argument === $kind) {
                $arguments[] = $name;
            } else {
                $options[] = $name;
            }
        }

        return [$arguments, $options, $complete];
    }

    private function attributeInputName(string $arguments, string $parameter): ?string
    {
        if ('' === trim($arguments)) {
            return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $parameter) ?? $parameter);
        }
        $arguments = $this->splitArguments(substr(trim($arguments), 1, -1));
        foreach ($arguments as $argument) {
            if (preg_match('/^\s*name\s*:\s*(.*)$/s', $argument, $match)) {
                return $this->literal($match[1]);
            }
        }
        if (isset($arguments[1])) {
            return $this->literal($arguments[1]);
        }

        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $parameter) ?? $parameter);
    }

    /** @return list<string> */
    private function splitArguments(string $arguments): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $escaped = false;
        for ($offset = 0, $length = \strlen($arguments); $offset < $length; ++$offset) {
            $character = $arguments[$offset];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }
                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
            } elseif (\in_array($character, ['(', '[', '{'], true)) {
                ++$depth;
            } elseif (\in_array($character, [')', ']', '}'], true)) {
                --$depth;
            } elseif (0 === $depth && ',' === $character) {
                $parts[] = substr($arguments, $start, $offset - $start);
                $start = $offset + 1;
            }
        }
        $parts[] = substr($arguments, $start);

        return $parts;
    }

    private function literal(string $expression): ?string
    {
        $expression = trim($expression);
        if (\strlen($expression) < 2 || !\in_array($expression[0], ["'", '"'], true) || !str_ends_with($expression, $expression[0])) {
            return null;
        }

        return PhpStringLiteralDecoder::decode($expression[0], substr($expression, 1, -1));
    }

    private function hasInputReceiver(PhpMethodCall $call, PhpDocument $php): bool
    {
        return array_any($php->receiverVariables($call), static fn ($variable): bool => \in_array(self::INPUT_INTERFACE, $variable->types, true));
    }

    /** @return list<string> */
    private function traits(string $text, PhpDocument $php, PhpTypeDeclaration $type): array
    {
        $body = substr($text, $type->startOffset, $type->endOffset - $type->startOffset);
        preg_match_all('/^\s*use\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*(?:\s*,\s*[\\\\A-Za-z_][\\\\A-Za-z0-9_]*)*)\s*;/m', $body, $matches);
        $traits = [];
        foreach ($matches[1] as $list) {
            foreach (preg_split('/\s*,\s*/', $list) ?: [] as $trait) {
                $traits[] = $php->resolveName($trait);
            }
        }
        $traits = array_values(array_unique($traits));
        sort($traits);

        return $traits;
    }

    /** @return list<array{start: int, end: int, closed: bool}> */
    private function methodBodyRanges(string $text, PhpTypeDeclaration $type, string $method): array
    {
        $source = substr($text, $type->startOffset, $type->endOffset - $type->startOffset);
        preg_match_all('/\bfunction\s+'.preg_quote($method, '/').'\s*\([^)]*\)\s*(?::\s*[^\{;]+)?\s*\{/s', $source, $matches, \PREG_OFFSET_CAPTURE);
        $ranges = [];
        foreach ($matches[0] as [$matched, $relativeOffset]) {
            $open = $type->startOffset + $relativeOffset + strrpos($matched, '{');
            $close = $this->delimiters->matching($text, $open, '{', '}');
            $ranges[] = ['start' => $open, 'end' => $close ?? $type->endOffset, 'closed' => null !== $close];
        }

        return $ranges;
    }

    /** @return array{int, int}|null */
    private function methodParameterRange(string $text, PhpTypeDeclaration $type, string $method): ?array
    {
        $source = substr($text, $type->startOffset, $type->endOffset - $type->startOffset);
        if (!preg_match('/\bfunction\s+'.preg_quote($method, '/').'\s*\(/', $source, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $open = $type->startOffset + $match[0][1] + strrpos($match[0][0], '(');
        $close = $this->delimiters->matching($text, $open, '(', ')');

        return null === $close ? null : [$open + 1, $close];
    }
}
