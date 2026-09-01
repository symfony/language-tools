<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocument;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Parser\Twig\TwigStringDecoder;
use Symfony\Lsp\Parser\Twig\TwigStringLiteral;

final class TwigTranslationReferenceExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigDocumentParser $parser,
        private readonly TwigCommentParser $comments,
        private readonly TranslationParameterAnalyzer $parameters,
    ) {
    }

    /** @return list<TranslationReference> */
    public function extract(string $uri, string $text): array
    {
        $document = $this->parser->parse($text);
        $masked = $this->comments->mask($text);
        $defaultDomain = $this->defaultDomain($document);

        return [
            ...$this->filterReferences($uri, $text, $masked, $document, $defaultDomain),
            ...$this->functionReferences($uri, $text, $document, $defaultDomain),
            ...$this->tagReferences($uri, $text, $masked, $defaultDomain),
        ];
    }

    private function defaultDomain(TwigDocument $document): string
    {
        foreach ($document->nodesOfType('tag_statement') as $statement) {
            $tag = $document->directChild($statement, 'tag');
            if (null === $tag || 'trans_default_domain' !== $document->text($tag)) {
                continue;
            }

            if (null !== $domain = $document->directStringLiteral($statement)) {
                return $domain->value;
            }
        }

        return 'messages';
    }

    /** @return list<TranslationReference> */
    private function filterReferences(string $uri, string $text, string $masked, TwigDocument $document, string $defaultDomain): array
    {
        $literals = [];
        foreach (['string', 'interpolated_string'] as $type) {
            foreach ($document->nodesOfType($type) as $node) {
                if (null !== $literal = $document->stringLiteral($node)) {
                    $literals[] = ['parent' => $node->parent, 'literal' => $literal];
                }
            }
        }

        $references = [];
        foreach ($document->nodesOfType('filter') as $filter) {
            $identifier = $document->directChild($filter, 'filter_identifier');
            if (null === $identifier || 'trans' !== $document->text($identifier)) {
                continue;
            }
            $key = $this->filteredLiteral($masked, $filter, $literals);
            if (null === $key) {
                continue;
            }
            $arguments = $this->arguments($document, $filter);
            $domain = $this->domain($document, $this->argument($arguments, 1, ['domain']), $defaultDomain);
            if (null === $domain) {
                continue;
            }
            $references[] = $this->reference(
                $key,
                $domain,
                $uri,
                $text,
                $this->parameters->twig($document, $this->argument($arguments, 0, ['arguments', 'parameters'])),
            );
        }

        return $references;
    }

    /**
     * @param list<array{parent: int|null, literal: TwigStringLiteral}> $literals
     */
    private function filteredLiteral(string $source, TreeSitterNode $filter, array $literals): ?TwigStringLiteral
    {
        $candidate = null;
        foreach ($literals as $literal) {
            if ($filter->parent !== $literal['parent'] || $literal['literal']->endOffset >= $filter->startByte) {
                continue;
            }
            if (null === $candidate || $literal['literal']->endOffset > $candidate->endOffset) {
                $candidate = $literal['literal'];
            }
        }
        if (null === $candidate) {
            return null;
        }
        $separator = substr($source, $candidate->endOffset + 1, $filter->startByte - $candidate->endOffset - 1);

        return 1 === preg_match('/^\s*\|\s*$/D', $separator) ? $candidate : null;
    }

    /** @return list<TranslationReference> */
    private function functionReferences(string $uri, string $text, TwigDocument $document, string $defaultDomain): array
    {
        $references = [];
        foreach ($document->nodesOfType('function_call') as $call) {
            $identifier = $document->directChild($call, 'function_identifier');
            if (null === $identifier || !\in_array($document->text($identifier), ['trans', 't'], true)) {
                continue;
            }
            $arguments = $this->arguments($document, $call);
            $keyArgument = $this->argument($arguments, 0, ['id', 'message']);
            $key = null === $keyArgument ? null : $document->soleStringLiteral($keyArgument);
            $domain = $this->domain($document, $this->argument($arguments, 2, ['domain']), $defaultDomain);
            if (null === $key || null === $domain) {
                continue;
            }
            $references[] = $this->reference(
                $key,
                $domain,
                $uri,
                $text,
                $this->parameters->twig($document, $this->argument($arguments, 1, ['arguments', 'parameters'])),
            );
        }

        return $references;
    }

    /** @return list<array{name: string|null, value: TreeSitterNode}> */
    private function arguments(TwigDocument $document, TreeSitterNode $call): array
    {
        $container = $document->directChild($call, 'arguments');
        if (null === $container) {
            return [];
        }

        $arguments = [];
        $recoveredName = null;
        foreach ($document->children($container) as $child) {
            if ('argument' !== $child->type) {
                $recoveredName = $this->argumentName($document, $child);

                continue;
            }
            $name = $document->directChild($child, 'argument_name');
            $arguments[] = [
                'name' => null === $name ? $recoveredName : $this->argumentName($document, $name),
                'value' => $document->directChild($child, 'argument_value') ?? $child,
            ];
            $recoveredName = null;
        }

        return $arguments;
    }

    private function argumentName(TwigDocument $document, TreeSitterNode $node): ?string
    {
        return 1 === preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*[:=]\s*$/D', $document->text($node), $match) ? $match[1] : null;
    }

    /**
     * @param list<array{name: string|null, value: TreeSitterNode}> $arguments
     * @param list<string>                                          $names
     */
    private function argument(array $arguments, int $position, array $names): ?TreeSitterNode
    {
        $positional = [];
        foreach ($arguments as $argument) {
            if (null === $argument['name']) {
                $positional[] = $argument['value'];
            } elseif (\in_array($argument['name'], $names, true)) {
                return $argument['value'];
            }
        }

        return $positional[$position] ?? null;
    }

    private function domain(TwigDocument $document, ?TreeSitterNode $argument, string $defaultDomain): ?string
    {
        return null === $argument ? $defaultDomain : $document->soleStringLiteral($argument)?->value;
    }

    /** @param list<string>|null $placeholders */
    private function reference(TwigStringLiteral $key, string $domain, string $uri, string $text, ?array $placeholders): TranslationReference
    {
        return new TranslationReference(
            $key->value,
            $domain,
            $uri,
            $this->converter->toRange($text, $key->startOffset, $key->endOffset - $key->startOffset),
            $placeholders,
        );
    }

    /** @return list<TranslationReference> */
    private function tagReferences(string $uri, string $text, string $masked, string $defaultDomain): array
    {
        preg_match_all(
            '/{%\s*trans(?:\s+from\s+(?|(\')((?:\\\\.|[^\'\\\\])+)\'|(\")((?:\\\\.|[^\"#\\\\])+)\"))?\s*%}(.+?){%\s*endtrans\s*%}/s',
            $masked,
            $matches,
            \PREG_OFFSET_CAPTURE,
        );
        $references = [];
        foreach ($matches[3] as $i => [$message, $offset]) {
            $domain = \is_string($matches[2][$i][0] ?? null) ? TwigStringDecoder::decode($matches[2][$i][0]) : $defaultDomain;
            $key = trim($message);
            $offset += \strlen($message) - \strlen(ltrim($message));
            $references[] = new TranslationReference($key, $domain, $uri, $this->converter->toRange($text, $offset, \strlen($key)));
        }

        return $references;
    }
}
