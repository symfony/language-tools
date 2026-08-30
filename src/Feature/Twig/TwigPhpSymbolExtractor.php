<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Php\PhpConstantKind;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpTypeKind;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TwigPhpSymbolExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $phpParser,
        private readonly TwigDocumentParser $twigParser,
        private readonly TwigCommentParser $comments,
    ) {
    }

    public function extract(string $uri, string $languageId, string $text): ?TwigPhpSymbolSourceFacts
    {
        return match ($languageId) {
            'php' => $this->phpFacts($uri, $text),
            'twig' => new TwigPhpSymbolSourceFacts($uri, references: $this->twigReferences($uri, $text)),
            default => null,
        };
    }

    public function referenceAt(string $uri, string $text, int $offset): ?TwigPhpSymbolReference
    {
        foreach ($this->twigReferences($uri, $text) as $reference) {
            if ($this->converter->containsByteOffset($text, $reference->range(), $offset, inclusiveEnd: true)) {
                return $reference;
            }
        }

        return null;
    }

    public function completionContext(string $text, int $offset): ?TwigPhpSymbolCompletionContext
    {
        $masked = $this->comments->mask($text);
        if (!$this->insideDirective($masked, $offset)) {
            return null;
        }
        $before = substr($masked, 0, $offset);
        if (preg_match('~\benum\s*\(\s*(?:enum\s*:\s*)?(?<quote>[\'\"])(?<class>[A-Za-z0-9_\x7f-\xff\\\\]*)\k<quote>\s*\)\s*\.\s*(?<prefix>[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*)?$~s', $before, $match, \PREG_OFFSET_CAPTURE)) {
            $className = $this->decodeClassName($match['class'][0]);
            $prefix = $match['prefix'][0] ?? '';
            if (null === $className) {
                return null;
            }
            $start = $offset - \strlen($prefix);

            return new TwigPhpSymbolCompletionContext(
                TwigPhpSymbolCompletionKind::EnumCase,
                $prefix,
                $this->completionRange($text, $start, $offset, false),
                $className,
            );
        }
        if (preg_match('~\bconstant\s*\(\s*(?:[A-Za-z_][A-Za-z0-9_]*\s*:\s*)?(?<quote>[\'\"])(?<value>[A-Za-z0-9_\x7f-\xff\\\\:]*)$~s', $before, $match, \PREG_OFFSET_CAPTURE)) {
            $raw = $match['value'][0];
            $separator = strrpos($raw, '::');
            if (false !== $separator) {
                $className = $this->decodeClassName(substr($raw, 0, $separator));
                $prefix = substr($raw, $separator + 2);
                if (null === $className || !$this->validIdentifierPrefix($prefix)) {
                    return null;
                }
                $start = $offset - \strlen($prefix);

                return new TwigPhpSymbolCompletionContext(
                    TwigPhpSymbolCompletionKind::ConstantMember,
                    $prefix,
                    $this->completionRange($text, $start, $offset, false),
                    $className,
                );
            }
            $prefix = $this->decodeClassPrefix($raw);
            if (null === $prefix) {
                return null;
            }

            return new TwigPhpSymbolCompletionContext(
                TwigPhpSymbolCompletionKind::ConstantType,
                $prefix,
                $this->completionRange($text, $match['value'][1], $offset, true),
            );
        }
        if (preg_match('~\b(?<function>enum_cases|enum)\s*\(\s*(?:[A-Za-z_][A-Za-z0-9_]*\s*:\s*)?(?<quote>[\'\"])(?<class>[A-Za-z0-9_\x7f-\xff\\\\]*)$~s', $before, $match, \PREG_OFFSET_CAPTURE)) {
            $prefix = $this->decodeClassPrefix($match['class'][0]);
            if (null === $prefix) {
                return null;
            }

            return new TwigPhpSymbolCompletionContext(
                TwigPhpSymbolCompletionKind::EnumType,
                $prefix,
                $this->completionRange($text, $match['class'][1], $offset, true),
            );
        }

        return null;
    }

    private function phpFacts(string $uri, string $text): TwigPhpSymbolSourceFacts
    {
        $document = $this->phpParser->parse($text);
        $types = $document->typeDeclarations;
        $typeKinds = [];
        foreach ($types as $type) {
            $typeKinds[strtolower(ltrim($type->name, '\\'))] = $type->kind;
        }
        $constants = [];
        foreach ($document->constantDeclarations as $constant) {
            if (PhpTypeKind::Trait_ !== ($typeKinds[strtolower(ltrim($constant->className, '\\'))] ?? null)) {
                $constants[] = $constant;
            }
        }
        $constantOwners = [];
        foreach ($constants as $constant) {
            $constantOwners[strtolower(ltrim($constant->className, '\\'))] = true;
        }
        $declarations = [];
        foreach ($types as $type) {
            if (!$type->isEnum() && !isset($constantOwners[strtolower(ltrim($type->name, '\\'))])) {
                continue;
            }
            $kind = match ($type->kind) {
                PhpTypeKind::Class_ => TwigPhpSymbolKind::Class_,
                PhpTypeKind::Interface_ => TwigPhpSymbolKind::Interface_,
                PhpTypeKind::Trait_ => TwigPhpSymbolKind::Trait_,
                PhpTypeKind::Enum => TwigPhpSymbolKind::Enum,
            };
            $declarations[] = new TwigPhpSymbolDeclaration(
                $kind,
                $type->name,
                null,
                $uri,
                $this->converter->toRange($text, $type->nameStartOffset, $type->nameEndOffset - $type->nameStartOffset),
                $type->signature,
                $type->description,
                true,
            );
        }
        foreach ($constants as $constant) {
            $declarations[] = new TwigPhpSymbolDeclaration(
                PhpConstantKind::ClassConstant === $constant->kind ? TwigPhpSymbolKind::ClassConstant : TwigPhpSymbolKind::EnumCase,
                $constant->className,
                $constant->name,
                $uri,
                $this->converter->toRange($text, $constant->nameStartOffset, $constant->nameEndOffset - $constant->nameStartOffset),
                $constant->signature,
                $constant->description,
                $constant->public,
            );
        }

        return new TwigPhpSymbolSourceFacts($uri, $declarations);
    }

    /** @return list<TwigPhpSymbolReference> */
    private function twigReferences(string $uri, string $text): array
    {
        $document = $this->twigParser->parse($text);
        $references = [];
        foreach ($document->nodesOfType('function_call') as $call) {
            $function = $document->directChild($call, 'function_identifier');
            if (null === $function || !\in_array($name = $document->text($function), ['constant', 'enum', 'enum_cases'], true)) {
                continue;
            }
            $arguments = $document->directChild($call, 'arguments');
            $argument = null === $arguments ? null : $document->directChild($arguments, 'argument');
            $literal = null === $argument ? null : $document->literalString($argument);
            if (null === $literal) {
                continue;
            }
            [$raw, $start, $end] = $literal;
            if ('constant' === $name) {
                $separator = strrpos($raw, '::');
                if (false === $separator || !$this->validIdentifier($memberName = substr($raw, $separator + 2))) {
                    continue;
                }
                $className = $this->decodeClassName(substr($raw, 0, $separator));
                if (null === $className) {
                    continue;
                }
                $references[] = $this->reference($className, null, $uri, $text, $start, $separator);
                $references[] = $this->reference($className, $memberName, $uri, $text, $start + $separator + 2, \strlen($memberName));

                continue;
            }
            $className = $this->decodeClassName($raw);
            if (null === $className) {
                continue;
            }
            $references[] = $this->reference($className, null, $uri, $text, $start, $end - $start);
            if ('enum' !== $name) {
                continue;
            }
            $after = substr($text, $call->endByte);
            if (1 === preg_match('/^\s*\.\s*([A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*)/', $after, $member, \PREG_OFFSET_CAPTURE)) {
                $memberName = $member[1][0];
                $references[] = $this->reference($className, $memberName, $uri, $text, $call->endByte + $member[1][1], \strlen($memberName));
            }
        }
        usort($references, static fn (TwigPhpSymbolReference $left, TwigPhpSymbolReference $right): int => [$left->range()->start->line, $left->range()->start->character] <=> [$right->range()->start->line, $right->range()->start->character]);

        return $references;
    }

    private function reference(string $className, ?string $memberName, string $uri, string $text, int $start, int $length): TwigPhpSymbolReference
    {
        return new TwigPhpSymbolReference(
            $className,
            $memberName,
            $uri,
            $this->converter->toRange($text, $start, $length),
        );
    }

    private function decodeClassName(string $raw): ?string
    {
        $name = $this->decodeClassPrefix($raw, true);

        return null === $name ? null : ltrim($name, '\\');
    }

    private function decodeClassPrefix(string $raw, bool $complete = false): ?string
    {
        $decoded = '';
        for ($offset = 0, $length = \strlen($raw); $offset < $length; ++$offset) {
            if ('\\' !== $raw[$offset]) {
                $decoded .= $raw[$offset];
                continue;
            }
            if ('\\' !== ($raw[$offset + 1] ?? null)) {
                return null;
            }
            $decoded .= '\\';
            ++$offset;
        }
        if ('' === $decoded) {
            return $complete ? null : '';
        }
        $name = str_starts_with($decoded, '\\') ? substr($decoded, 1) : $decoded;
        if ('' === $name || str_starts_with($name, '\\')) {
            return null;
        }
        $segments = explode('\\', $name);
        foreach ($segments as $index => $segment) {
            if ('' === $segment && !$complete && $index === array_key_last($segments)) {
                continue;
            }
            if (!$this->validIdentifier($segment)) {
                return null;
            }
        }

        return $decoded;
    }

    private function completionRange(string $text, int $start, int $cursor, bool $className): Range
    {
        $end = $cursor;
        $length = \strlen($text);
        $pattern = $className ? '/[A-Za-z0-9_\x7f-\xff\\\\]/' : '/[A-Za-z0-9_\x7f-\xff]/';
        while ($end < $length && 1 === preg_match($pattern, $text[$end])) {
            ++$end;
        }

        return $this->converter->toRange($text, $start, $end - $start);
    }

    private function validIdentifier(string $value): bool
    {
        return 1 === preg_match('/^[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*$/D', $value);
    }

    private function validIdentifierPrefix(string $value): bool
    {
        return '' === $value || 1 === preg_match('/^[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*$/D', $value);
    }

    private function insideDirective(string $text, int $offset): bool
    {
        $close = null;
        $quote = null;
        $escaped = false;
        $brackets = [];
        for ($cursor = 0; $cursor < $offset; ++$cursor) {
            $character = $text[$cursor];
            $pair = substr($text, $cursor, 2);
            if (null === $close) {
                if ('{{' === $pair) {
                    $close = '}}';
                    $brackets = [];
                    ++$cursor;
                } elseif ('{%' === $pair) {
                    $close = '%}';
                    $brackets = [];
                    ++$cursor;
                }
                continue;
            }
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
                $brackets[] = ['(' => ')', '[' => ']', '{' => '}'][$character];
            } elseif ([] !== $brackets && $character === $brackets[array_key_last($brackets)]) {
                array_pop($brackets);
            } elseif ([] === $brackets && $close === $pair) {
                $close = null;
                ++$cursor;
            }
        }

        return null !== $close;
    }
}
