<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpAttribute;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;
use Symfony\Lsp\Parser\QuotedArgumentMatcher;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TemplateReferenceExtractor
{
    private const TEMPLATE_ATTRIBUTE = 'Symfony\Bridge\Twig\Attribute\Template';

    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly TwigDocumentParser $twigParser,
        private readonly QuotedArgumentMatcher $matcher,
        private readonly PhpCommentParserInterface $phpComments,
        private readonly PhpParserInterface $phpParser,
    ) {
    }

    /** @return list<TemplateReference> */
    public function extract(string $uri, string $languageId, string $text): array
    {
        if ('twig' === $languageId) {
            return $this->twigReferences($uri, $text);
        }
        if ('php' !== $languageId) {
            return [];
        }

        $masked = $this->phpComments->mask($text);
        $references = [];
        foreach ($this->matcher->methodCalls($masked, ['render', 'renderView']) as $call) {
            $variables = [];
            if (1 === preg_match('/^\s*,\s*\[([^\]]*)\]/', substr($masked, $call->end()), $arrayMatch)) {
                preg_match_all('/([\'"])([^\'"]+)\1\s*=>/', $arrayMatch[1], $keys);
                $variables = array_values(array_unique($keys[2]));
            }
            $references[] = new TemplateReference($call->value, $uri, $call->range, $variables);
        }
        foreach ($this->phpParser->parse($text)->attributes as $attribute) {
            if (self::TEMPLATE_ATTRIBUTE !== $attribute->name) {
                continue;
            }
            $template = $this->attributeArgument($attribute, 'template', 0)?->stringLiteral;
            if (null === $template || '' === $template->value) {
                continue;
            }
            $references[] = new TemplateReference(
                $template->value,
                $uri,
                new Range(
                    $this->positionConverter->toPosition($text, $template->startOffset),
                    $this->positionConverter->toPosition($text, $template->endOffset),
                ),
                $this->attributeVariables($this->attributeArgument($attribute, 'vars', 1)?->expression),
            );
        }

        return $this->sorted($references);
    }

    public function at(string $uri, string $languageId, string $text, int $offset): ?TemplateReference
    {
        foreach ($this->extract($uri, $languageId, $text) as $reference) {
            $start = $this->positionConverter->toByteOffset($text, $reference->range()->start);
            $end = $this->positionConverter->toByteOffset($text, $reference->range()->end);
            if ($offset >= $start && $offset <= $end) {
                return $reference;
            }
        }

        return null;
    }

    /** @return list<TemplateReference> */
    private function twigReferences(string $uri, string $text): array
    {
        $document = $this->twigParser->parse($text);
        $references = [];
        foreach ($document->nodesOfType('tag_statement') as $statement) {
            $tag = $document->directChild($statement, 'tag');
            $target = $document->directString($statement);
            if (null === $tag || null === $target || !\in_array($document->text($tag), ['embed', 'extends', 'from', 'import', 'include', 'use'], true)) {
                continue;
            }
            if (null !== $literal = $document->string($target)) {
                $references[] = $this->reference($literal[0], $uri, $text, $literal[1]);
            }
        }
        foreach ($document->nodesOfType('function_call') as $call) {
            $name = $document->directChild($call, 'function_identifier');
            if (null === $name || !\in_array($document->text($name), ['include', 'source'], true)) {
                continue;
            }
            $arguments = $document->directChild($call, 'arguments');
            $argument = null === $arguments ? null : $document->directChild($arguments, 'argument');
            $literal = null === $argument ? null : $document->literalString($argument);
            if (null !== $literal) {
                $references[] = $this->reference($literal[0], $uri, $text, $literal[1]);
            }
        }

        return $this->sorted($references);
    }

    private function attributeArgument(PhpAttribute $attribute, string $name, int $position): ?PhpArgument
    {
        if (null !== $argument = $attribute->argument($name)) {
            return $argument;
        }
        $positional = $attribute->argument($position);

        return null === $positional?->name ? $positional : null;
    }

    /** @return list<string> */
    private function attributeVariables(?string $expression): array
    {
        if (null === $expression) {
            return [];
        }
        $variables = [];
        $first = true;
        foreach (\PhpToken::tokenize('<?php '.$expression) as $token) {
            if ($token->is([\T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }
            if ($first) {
                $first = false;
                if (!$token->is(\T_ARRAY) && '[' !== $token->text) {
                    return [];
                }
                continue;
            }
            if ($token->is(\T_CONSTANT_ENCAPSED_STRING)) {
                $variable = PhpStringLiteralDecoder::decode($token->text[0], substr($token->text, 1, -1));
                if ('' !== $variable) {
                    $variables[] = $variable;
                }
                continue;
            }
            if (!\in_array($token->text, ['(', ')', '[', ']', ','], true)) {
                return [];
            }
        }

        return array_values(array_unique($variables));
    }

    /**
     * @param list<TemplateReference> $references
     *
     * @return list<TemplateReference>
     */
    private function sorted(array $references): array
    {
        usort($references, static fn (TemplateReference $left, TemplateReference $right): int => $left->range()->start->line <=> $right->range()->start->line ?: $left->range()->start->character <=> $right->range()->start->character);

        return $references;
    }

    /** @param list<string> $variables */
    private function reference(string $name, string $uri, string $text, int $offset, array $variables = []): TemplateReference
    {
        return new TemplateReference(
            $name,
            $uri,
            new Range(
                $this->positionConverter->toPosition($text, $offset),
                $this->positionConverter->toPosition($text, $offset + \strlen($name)),
            ),
            $variables,
        );
    }
}
