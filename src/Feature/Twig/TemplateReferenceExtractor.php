<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Php\PhpLiteralArrayKeyParser;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;
use Symfony\Lsp\Parser\QuotedArgument;
use Symfony\Lsp\Parser\QuotedArgumentMatcher;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TemplateReferenceExtractor
{
    private const TEMPLATE_ATTRIBUTE = 'Symfony\Bridge\Twig\Attribute\Template';

    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly TwigDocumentParser $twigParser,
        private readonly TwigCallArgumentResolver $twigArguments,
        private readonly QuotedArgumentMatcher $matcher,
        private readonly PhpCommentParserInterface $phpComments,
        private readonly PhpParserInterface $phpParser,
        private readonly PhpLiteralArrayKeyParser $arrayKeys,
        private readonly BalancedDelimiterMatcher $delimiters,
    ) {
    }

    /** @return list<TemplateReference> */
    public function extract(SourceDocument $document): array
    {
        if ('twig' === $document->languageId) {
            return $this->twigReferences($document->uri, $document->text);
        }
        if ('php' !== $document->languageId) {
            return [];
        }

        $php = $this->phpParser->parse($document->text);
        $references = [];
        foreach ($php->methodCalls as $call) {
            if (!\in_array($call->method, ['render', 'renderView'], true)) {
                continue;
            }
            $template = $call->namedOrPositionalArgument('view', 0)?->stringLiteral;
            if (null === $template || '' === $template->value) {
                continue;
            }
            $references[] = $this->reference(
                $template->value,
                $document->uri,
                $document->text,
                $template->startOffset,
                $template->endOffset,
                $this->literalArrayKeys($call->namedOrPositionalArgument('parameters', 1)?->expression),
            );
        }

        $masked = $this->phpComments->mask($document->text);
        foreach ($this->matcher->methodCalls($masked, ['render', 'renderView']) as $call) {
            if ('::' !== substr($masked, max(0, $call->nameOffset - 2), 2)) {
                continue;
            }
            $references[] = new TemplateReference(
                $call->value,
                $document->uri,
                $call->range,
                $this->staticVariables($document->text, $masked, $call),
            );
        }
        foreach ($php->attributes as $attribute) {
            if (self::TEMPLATE_ATTRIBUTE !== $attribute->name) {
                continue;
            }
            $template = $attribute->namedOrPositionalArgument('template', 0)?->stringLiteral;
            if (null === $template || '' === $template->value) {
                continue;
            }
            $references[] = new TemplateReference(
                $template->value,
                $document->uri,
                new Range(
                    $this->positionConverter->toPosition($document->text, $template->startOffset),
                    $this->positionConverter->toPosition($document->text, $template->endOffset),
                ),
                $this->attributeVariables($attribute->namedOrPositionalArgument('vars', 1)?->expression),
            );
        }

        return $this->sorted($references);
    }

    /** @return list<TemplateReference> */
    private function twigReferences(string $uri, string $text): array
    {
        $document = $this->twigParser->parse($text);
        $references = [];
        foreach ($document->nodesOfType('tag_statement') as $statement) {
            $tag = $document->directChild($statement, 'tag');
            $target = $document->directStringLiteral($statement);
            if (null === $tag || null === $target || !\in_array($document->text($tag), ['embed', 'extends', 'from', 'import', 'include', 'use'], true)) {
                continue;
            }
            $references[] = $this->reference($target->value, $uri, $text, $target->startOffset, $target->endOffset);
        }
        foreach ($document->nodesOfType('function_call') as $call) {
            $name = $document->directChild($call, 'function_identifier');
            if (null === $name) {
                continue;
            }
            $function = $document->text($name);
            if (!\in_array($function, ['include', 'source'], true)) {
                continue;
            }
            $arguments = $this->twigArguments->resolve($document, $call);
            $argument = $arguments->get(0, 'include' === $function ? 'template' : 'name');
            $literal = null === $argument ? null : $document->soleStringLiteral($argument);
            if (null !== $literal) {
                $references[] = $this->reference($literal->value, $uri, $text, $literal->startOffset, $literal->endOffset);
            }
        }

        return $this->sorted($references);
    }

    /** @return list<string> */
    private function literalArrayKeys(?string $expression): array
    {
        $expression = trim((string) $expression);
        if (str_starts_with($expression, '[') && str_ends_with($expression, ']')) {
            $items = substr($expression, 1, -1);
        } elseif (preg_match('/^array\s*\((.*)\)$/is', $expression, $match)) {
            $items = $match[1];
        } else {
            return [];
        }
        $keys = $this->arrayKeys->parse($items, allowNestedUnpacking: true, collectPartialLiteralKeys: true);
        if (null === $keys) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static fn ($key): string => $key->value, $keys), static fn (string $key): bool => '' !== $key)));
    }

    /** @return list<string> */
    private function staticVariables(string $text, string $masked, QuotedArgument $call): array
    {
        $tailOffset = $call->end();
        $tail = substr($masked, $tailOffset);
        if (1 !== preg_match('/^\s*,\s*(\[|array\s*\()/i', $tail, $match, \PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $expressionOffset = $tailOffset + $match[1][1];
        $opening = str_starts_with($match[1][0], '[') ? '[' : '(';
        $open = '[' === $opening ? $expressionOffset : $expressionOffset + (int) strrpos($match[1][0], '(');
        $close = $this->delimiters->matching($masked, $open, $opening, '[' === $opening ? ']' : ')');
        if (null === $close) {
            return [];
        }

        return $this->literalArrayKeys(substr($text, $expressionOffset, $close - $expressionOffset + 1));
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
        usort($references, static fn (TemplateReference $left, TemplateReference $right): int => $left->range->start->line <=> $right->range->start->line ?: $left->range->start->character <=> $right->range->start->character);

        return $references;
    }

    /** @param list<string> $variables */
    private function reference(string $name, string $uri, string $text, int $startOffset, int $endOffset, array $variables = []): TemplateReference
    {
        return new TemplateReference(
            $name,
            $uri,
            new Range(
                $this->positionConverter->toPosition($text, $startOffset),
                $this->positionConverter->toPosition($text, $endOffset),
            ),
            $variables,
        );
    }
}
