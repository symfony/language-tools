<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpArgumentCursor;
use Symfony\Lsp\Parser\Php\PhpAttribute;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpStringLiteral;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TemplateReferenceExtractor
{
    private const TEMPLATE_ATTRIBUTE = 'Symfony\Bridge\Twig\Attribute\Template';

    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly TwigDocumentParser $twigParser,
        private readonly PhpParserInterface $phpParser,
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

    /** The template name being typed at the cursor, in a call or attribute the index reads references from. */
    public function phpCompletionAt(string $source, int $offset, ?DependencyInjectionSourceIndex $classIndex = null): ?TemplateCompletionContext
    {
        $php = $this->phpParser->parse($source);
        $cursor = $php->argumentCursorAt($offset);
        $call = $cursor?->call;
        if (null === $cursor || !$cursor->isArgumentLiteral()) {
            return null;
        }
        if ($call instanceof PhpAttribute) {
            return self::TEMPLATE_ATTRIBUTE === $call->name && $cursor->isNamedOrPositional('template', 0)
                ? $this->completionContext($cursor, $source, $offset)
                : null;
        }
        if (!$call instanceof PhpMethodCall || !\in_array($call->method, ['render', 'renderView'], true)) {
            return null;
        }
        $receiver = $this->phpReferences->receiver($php, $call);
        if (null === $receiver
            || $cursor->argument !== $call->namedOrPositionalArgument($receiver['templateArgumentName'], 0)
            || !TemplatePhpReferenceResolver::supportsReceiver($receiver['className'], $receiver['requiredParentClassNames'], $classIndex, $php)
        ) {
            return null;
        }

        return $this->completionContext($cursor, $source, $offset);
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

    private function completionContext(PhpArgumentCursor $cursor, string $source, int $offset): TemplateCompletionContext
    {
        return new TemplateCompletionContext(
            $cursor->prefix,
            new Range(
                $this->positionConverter->toPosition($source, $cursor->prefixStartOffset),
                $this->positionConverter->toPosition($source, $offset),
            ),
        );
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
            $variables = $this->literalArrayKeyValues($php->literalArray($parameters)->keys ?? []);
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
                $this->attributeVariables($php, $attribute->namedOrPositionalArgument('vars', 1)),
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
        foreach ($document->calls('include', 'source') as $call) {
            $literal = $call->argument(0, 'include' === $call->name ? 'template' : 'name')?->literal();
            if (null !== $literal) {
                $references[] = $this->reference($literal->value, $uri, $text, $literal->startOffset, $literal->endOffset);
            }
        }

        return $this->sorted($references);
    }

    /**
     * @param list<PhpStringLiteral> $keys
     *
     * @return list<string>
     */
    private function literalArrayKeyValues(array $keys): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (PhpStringLiteral $key): string => $key->value, $keys), static fn (string $key): bool => '' !== $key)));
    }

    /**
     * The variable names a `#[Template]` attribute lists, which are the string
     * values of a keyless array literal.
     *
     * @return list<string>
     */
    private function attributeVariables(PhpDocument $php, ?PhpArgument $argument): array
    {
        $array = $php->literalArray($argument);
        if (null === $array || !$array->complete || $array->hasUnknownKeys) {
            return [];
        }
        $variables = [];
        foreach ($array->entries as $entry) {
            if (null !== $entry->key || null === $entry->stringValue) {
                return [];
            }
            if ('' !== $entry->stringValue->value) {
                $variables[] = $entry->stringValue->value;
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
