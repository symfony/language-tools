<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;
use Symfony\Lsp\Parser\Php\PhpTypeDeclaration;
use Symfony\Lsp\Parser\QuotedArgumentMatcher;

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
        private readonly QuotedArgumentMatcher $matcher,
        private readonly PhpCommentParserInterface $phpComments,
    ) {
    }

    public function extract(string $uri, string $languageId, string $text): ConsoleSourceFacts
    {
        if ('php' !== $languageId) {
            return new ConsoleSourceFacts($uri, [], []);
        }

        $masked = $this->phpComments->mask($text);
        $php = $this->parser->parse($masked);
        $declarations = [];
        foreach ($php->typeDeclarations() as $type) {
            if (!$type->isClass() && !$this->isTrait($masked, $type)) {
                continue;
            }
            $declarations[] = $this->declaration($masked, $php, $type);
        }

        $references = [];
        foreach ($this->matcher->methodCalls($masked, ['getArgument', 'getOption']) as $call) {
            $type = $this->containingType($php, $call->nameOffset);
            $receiver = $this->receiverName($masked, $call->nameOffset);
            if (null === $type || null === $receiver || !isset($this->inputVariables($masked, $php, $type)[$receiver])) {
                continue;
            }
            $references[] = new ConsoleInputReference(
                'getArgument' === $call->name ? ConsoleInputKind::Argument : ConsoleInputKind::Option,
                $call->value,
                $uri,
                $call->range,
                $type->name(),
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
        $php = $this->parser->parse($masked);
        $methodOffset = $match[3][1];
        $type = $this->containingType($php, $methodOffset);
        $receiver = \is_string($match[2][0] ?? null) ? $match[2][0] : ($match[1][0] ?? null);
        if (null === $type || !\is_string($receiver) || !isset($this->inputVariables($masked, $php, $type)[$receiver])) {
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
            $type->name(),
        );
    }

    private function declaration(string $text, PhpDocument $php, PhpTypeDeclaration $type): ConsoleCommandDeclaration
    {
        $arguments = [];
        $options = [];
        $complete = true;
        $configureRanges = $this->methodBodyRanges($text, $type, 'configure');
        foreach ($php->methodCalls() as $call) {
            if (!$this->isDefinitionReceiver($call->receiver()) || !$this->inRanges($call->startOffset(), $configureRanges)) {
                continue;
            }
            if ('addArgument' === $call->method() || 'addOption' === $call->method()) {
                $name = $call->argument(0)?->stringLiteral()?->value();
                if (null === $name) {
                    $complete = false;
                    continue;
                }
                if ('addArgument' === $call->method()) {
                    $arguments[] = $name;
                } else {
                    $options[] = $name;
                }
                continue;
            }
            if ('setDefinition' !== $call->method()) {
                continue;
            }
            $expression = $call->argument(0)?->expression();
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

        $command = $this->hasAttribute($text, $php, $type, self::AS_COMMAND_ATTRIBUTE)
            || 0 === strcasecmp(self::COMMAND, (string) $type->parentClassName());
        [$attributeArguments, $attributeOptions, $attributesComplete] = $this->invokableAttributes($text, $php, $type);
        $arguments = [...$arguments, ...$attributeArguments];
        $options = [...$options, ...$attributeOptions];
        $complete = $complete && $attributesComplete;

        $arguments = array_values(array_unique($arguments));
        $options = array_values(array_unique($options));
        sort($arguments);
        sort($options);

        return new ConsoleCommandDeclaration(
            $type->name(),
            $type->parentClassName(),
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
        $document = $this->parser->parse('<?php '.$expression.';');
        $arguments = [];
        $options = [];
        $complete = 1 !== preg_match('/\$|\.\.\./', $expression);
        $recognized = false;
        foreach ($document->objectCreations() as $creation) {
            $shortName = substr($creation->className(), (int) strrpos('\\'.$creation->className(), '\\'));
            if ('InputDefinition' === $shortName) {
                $recognized = true;
                continue;
            }
            if (!\in_array($shortName, ['InputArgument', 'InputOption'], true)) {
                $complete = false;
                continue;
            }
            $recognized = true;
            $name = $creation->argument(0)?->stringLiteral()?->value();
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

    /** @return array<string, true> */
    private function inputVariables(string $text, PhpDocument $php, PhpTypeDeclaration $type): array
    {
        $source = substr($text, $type->startOffset(), $type->endOffset() - $type->startOffset());
        preg_match_all('/(?<type>\??\\\\?[A-Za-z_][\\\\A-Za-z0-9_]*)\s+\$(?<name>[A-Za-z_][A-Za-z0-9_]*)/', $source, $matches, \PREG_SET_ORDER);
        $variables = [];
        foreach ($matches as $match) {
            if (self::INPUT_INTERFACE === $php->resolveName(ltrim($match['type'], '?'))) {
                $variables[$match['name']] = true;
            }
        }

        return $variables;
    }

    private function receiverName(string $text, int $methodNameOffset): ?string
    {
        $before = substr($text, 0, max(0, $methodNameOffset - 2));
        if (!preg_match('/(?:\$this\s*->\s*|\$)([A-Za-z_][A-Za-z0-9_]*)\s*$/', $before, $match)) {
            return null;
        }

        return $match[1];
    }

    private function containingType(PhpDocument $php, int $offset): ?PhpTypeDeclaration
    {
        foreach ($php->typeDeclarations() as $type) {
            if ($offset >= $type->startOffset() && $offset <= $type->endOffset()) {
                return $type;
            }
        }

        return null;
    }

    private function isTrait(string $text, PhpTypeDeclaration $type): bool
    {
        return 1 === preg_match('/\btrait\s*$/', substr($text, $type->startOffset(), $type->nameStartOffset() - $type->startOffset()));
    }

    private function hasAttribute(string $text, PhpDocument $php, PhpTypeDeclaration $type, string $attribute): bool
    {
        $header = substr($text, $type->startOffset(), $type->nameStartOffset() - $type->startOffset());
        preg_match_all('/#\[\s*([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\b/', $header, $attributes);
        foreach ($attributes[1] as $name) {
            if ($attribute === $php->resolveName($name)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function traits(string $text, PhpDocument $php, PhpTypeDeclaration $type): array
    {
        $body = substr($text, $type->startOffset(), $type->endOffset() - $type->startOffset());
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
        $source = substr($text, $type->startOffset(), $type->endOffset() - $type->startOffset());
        preg_match_all('/\bfunction\s+'.preg_quote($method, '/').'\s*\([^)]*\)\s*(?::\s*[^\{;]+)?\s*\{/s', $source, $matches, \PREG_OFFSET_CAPTURE);
        $ranges = [];
        foreach ($matches[0] as [$matched, $relativeOffset]) {
            $open = $type->startOffset() + $relativeOffset + strrpos($matched, '{');
            $close = $this->matchingDelimiter($text, $open, '{', '}');
            $ranges[] = ['start' => $open, 'end' => $close ?? $type->endOffset(), 'closed' => null !== $close];
        }

        return $ranges;
    }

    /** @param list<array{start: int, end: int, closed: bool}> $ranges */
    private function inRanges(int $offset, array $ranges): bool
    {
        return array_any($ranges, static fn (array $range): bool => $offset >= $range['start'] && $offset <= $range['end']);
    }

    /** @return array{int, int}|null */
    private function methodParameterRange(string $text, PhpTypeDeclaration $type, string $method): ?array
    {
        $source = substr($text, $type->startOffset(), $type->endOffset() - $type->startOffset());
        if (!preg_match('/\bfunction\s+'.preg_quote($method, '/').'\s*\(/', $source, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $open = $type->startOffset() + $match[0][1] + strrpos($match[0][0], '(');
        $close = $this->matchingDelimiter($text, $open, '(', ')');

        return null === $close ? null : [$open + 1, $close];
    }

    private function matchingDelimiter(string $text, int $open, string $opening, string $closing): ?int
    {
        $depth = 1;
        $quote = null;
        $escaped = false;
        for ($offset = $open + 1, $length = \strlen($text); $offset < $length; ++$offset) {
            $character = $text[$offset];
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
            } elseif ($opening === $character) {
                ++$depth;
            } elseif ($closing === $character && 0 === --$depth) {
                return $offset;
            }
        }

        return null;
    }
}
