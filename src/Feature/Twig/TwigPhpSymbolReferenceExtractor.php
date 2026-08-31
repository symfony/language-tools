<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\Twig\TwigDocument;
use Symfony\Lsp\Parser\Twig\TwigStringLiteral;

final class TwigPhpSymbolReferenceExtractor
{
    public function __construct(private readonly PositionConverter $converter)
    {
    }

    /** @return list<TwigPhpSymbolReference> */
    public function extract(string $uri, string $text, TwigDocument $document): array
    {
        $references = [];
        foreach ($document->nodesOfType('function_call') as $call) {
            $function = $document->directChild($call, 'function_identifier');
            if (null === $function || !\in_array($name = $document->text($function), ['constant', 'enum', 'enum_cases'], true)) {
                continue;
            }
            $arguments = $document->directChild($call, 'arguments');
            $argument = null === $arguments ? null : $document->directChild($arguments, 'argument');
            $literal = null === $argument ? null : $document->soleStringLiteral($argument);
            if (null === $literal) {
                continue;
            }
            if ('constant' === $name) {
                $references = [...$references, ...$this->constantReferences($literal, $uri, $text)];
            } else {
                $references = [...$references, ...$this->enumReferences($name, $literal, $call, $uri, $text)];
            }
        }
        usort($references, static fn (TwigPhpSymbolReference $left, TwigPhpSymbolReference $right): int => [$left->range->start->line, $left->range->start->character] <=> [$right->range->start->line, $right->range->start->character]);

        return $references;
    }

    /** @return list<TwigPhpSymbolReference> */
    private function constantReferences(TwigStringLiteral $literal, string $uri, string $text): array
    {
        $separator = strrpos($literal->value, '::');
        $rawSeparator = strrpos($literal->raw, '::');
        if (false === $separator || false === $rawSeparator || !$this->validIdentifier($memberName = substr($literal->value, $separator + 2))) {
            return [];
        }
        $className = $this->className(substr($literal->value, 0, $separator));
        if (null === $className) {
            return [];
        }

        return [
            $this->reference($className, null, $uri, $text, $literal->startOffset, $rawSeparator),
            $this->reference($className, $memberName, $uri, $text, $literal->startOffset + $rawSeparator + 2, $literal->endOffset - $literal->startOffset - $rawSeparator - 2),
        ];
    }

    /** @return list<TwigPhpSymbolReference> */
    private function enumReferences(string $function, TwigStringLiteral $literal, TreeSitterNode $call, string $uri, string $text): array
    {
        $className = $this->className($literal->value);
        if (null === $className) {
            return [];
        }
        $references = [
            $this->reference($className, null, $uri, $text, $literal->startOffset, $literal->endOffset - $literal->startOffset),
        ];
        if ('enum' !== $function) {
            return $references;
        }
        $after = substr($text, $call->endByte);
        if (1 === preg_match('/^\s*\.\s*([A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*)/', $after, $member, \PREG_OFFSET_CAPTURE)) {
            $memberName = $member[1][0];
            $references[] = $this->reference($className, $memberName, $uri, $text, $call->endByte + $member[1][1], \strlen($memberName));
        }

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

    private function className(string $value): ?string
    {
        if ('' === $value) {
            return null;
        }
        $name = str_starts_with($value, '\\') ? substr($value, 1) : $value;
        if ('' === $name || str_starts_with($name, '\\')) {
            return null;
        }
        foreach (explode('\\', $name) as $segment) {
            if (!$this->validIdentifier($segment)) {
                return null;
            }
        }

        return $name;
    }

    private function validIdentifier(string $value): bool
    {
        return 1 === preg_match('/^[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*$/D', $value);
    }
}
