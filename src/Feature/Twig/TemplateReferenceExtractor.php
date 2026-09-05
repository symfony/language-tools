<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpLiteralArrayKeyParser;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpStringLiteral;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TemplateReferenceExtractor
{
    private const TEMPLATE_ATTRIBUTE = 'Symfony\Bridge\Twig\Attribute\Template';

    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly TwigDocumentParser $twigParser,
        private readonly TwigCallArgumentResolver $twigArguments,
        private readonly PhpParserInterface $phpParser,
        private readonly PhpLiteralArrayKeyParser $arrayKeys,
        private readonly TemplatePhpReferenceResolver $phpReferences,
    ) {
    }

    /** @return list<TemplateReference> */
    public function extract(SourceDocument $document, ?DependencyInjectionSourceIndex $classIndex = null): array
    {
        if ('twig' === $document->languageId) {
            return $this->twigReferences($document->uri, $document->text);
        }
        if ('php' !== $document->languageId) {
            return [];
        }

        $php = $this->phpParser->parse($document->text);

        return array_values(array_filter(
            $this->phpReferences($document, $php),
            static fn (TemplateReference $reference): bool => TemplatePhpReferenceResolver::supports($reference, $classIndex, $php),
        ));
    }

    public function supportsPhpRenderAt(string $source, int $offset, ?DependencyInjectionSourceIndex $classIndex = null): bool
    {
        $php = $this->phpParser->parse($source);
        $candidate = null;
        foreach ($php->methodCalls as $call) {
            if (!\in_array($call->method, ['render', 'renderView'], true) || $call->startOffset > $offset || $call->endOffset < $offset) {
                continue;
            }
            if (null === $candidate || $call->startOffset > $candidate->startOffset) {
                $candidate = $call;
            }
        }
        if (null === $candidate) {
            return false;
        }
        $receiver = $this->phpReferences->receiver($php, $candidate);
        if (null === $receiver || null === $candidate->namedOrPositionalArgument($receiver['templateArgumentName'], 0)) {
            return false;
        }

        return TemplatePhpReferenceResolver::supportsReceiver($receiver['className'], $receiver['requiredParentClassNames'], $classIndex, $php);
    }

    /** @return list<TemplateReference> */
    public function extractCandidates(SourceDocument $document): array
    {
        if ('twig' === $document->languageId) {
            return $this->twigReferences($document->uri, $document->text);
        }
        if ('php' !== $document->languageId) {
            return [];
        }

        return $this->phpReferences($document, $this->phpParser->parse($document->text));
    }

    /** @return list<TemplateReference> */
    private function phpReferences(SourceDocument $document, PhpDocument $php): array
    {
        $references = [];
        foreach ($php->methodCalls as $call) {
            if (!\in_array($call->method, ['render', 'renderView'], true)) {
                continue;
            }
            $receiver = $this->phpReferences->receiver($php, $call);
            if (null === $receiver) {
                continue;
            }
            $template = $call->namedOrPositionalArgument($receiver['templateArgumentName'], 0)?->stringLiteral;
            if (null === $template || '' === $template->value) {
                continue;
            }
            $parameters = $call->namedOrPositionalArgument($receiver['variablesArgumentName'], 1);
            $parametersExpression = $parameters?->expression;
            $parametersOffset = $parameters?->expressionStartOffset;
            $variables = !\is_string($parametersExpression) || !\is_int($parametersOffset) ? [] : $this->literalArrayKeyValues($this->arrayKeys->parseExpression(
                $parametersExpression,
                allowNestedUnpacking: true,
                collectPartialLiteralKeys: true,
                sourceOffset: $parametersOffset,
            ));
            $references[] = $this->reference(
                $template->value,
                $document->uri,
                $document->text,
                $template->startOffset,
                $template->endOffset,
                $variables,
                $receiver['className'],
                $receiver['requiredParentClassNames'],
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

    /**
     * @param list<PhpStringLiteral>|null $keys
     *
     * @return list<string>
     */
    private function literalArrayKeyValues(?array $keys): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (PhpStringLiteral $key): string => $key->value, $keys ?? []), static fn (string $key): bool => '' !== $key)));
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

    /**
     * @param list<string> $variables
     * @param list<string> $requiredParentClassNames
     */
    private function reference(
        string $name,
        string $uri,
        string $text,
        int $startOffset,
        int $endOffset,
        array $variables = [],
        ?string $receiverClassName = null,
        array $requiredParentClassNames = [],
    ): TemplateReference {
        return new TemplateReference(
            $name,
            $uri,
            new Range(
                $this->positionConverter->toPosition($text, $startOffset),
                $this->positionConverter->toPosition($text, $endOffset),
            ),
            $variables,
            $receiverClassName,
            $requiredParentClassNames,
        );
    }
}
