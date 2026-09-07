<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
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
        private readonly TwigCallArgumentResolver $arguments,
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

        $references = [];
        foreach ($this->calls($document, $masked) as $call) {
            $domain = $this->domain($document, $call['domain'], $defaultDomain);
            if (null !== $domain) {
                $references[] = $this->reference(
                    $call['key'],
                    $domain,
                    $uri,
                    $text,
                    $this->parameters->twig($document, $call['parameters']),
                );
            }
        }

        return [...$references, ...$this->tagReferences($uri, $text, $masked, $defaultDomain)];
    }

    /**
     * The domain scoping the key literal at $offset: the call's literal domain,
     * the template's default domain when the call sets none, or null when the
     * call sets a domain that isn't statically known.
     */
    public function completionDomain(string $text, int $offset): ?string
    {
        $document = $this->parser->parse($text);
        $defaultDomain = $this->defaultDomain($document);
        foreach ($this->calls($document, $this->comments->mask($text)) as $call) {
            if ($offset >= $call['key']->startOffset && $offset <= $call['key']->endOffset) {
                return $this->domain($document, $call['domain'], $defaultDomain);
            }
        }

        return $defaultDomain;
    }

    /** @return list<array{key: TwigStringLiteral, domain: ?TreeSitterNode, parameters: ?TreeSitterNode}> */
    private function calls(TwigDocument $document, string $masked): array
    {
        return [...$this->filterCalls($document, $masked), ...$this->functionCalls($document)];
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

    /** @return list<array{key: TwigStringLiteral, domain: ?TreeSitterNode, parameters: ?TreeSitterNode}> */
    private function filterCalls(TwigDocument $document, string $masked): array
    {
        $literals = [];
        foreach (['string', 'interpolated_string'] as $type) {
            foreach ($document->nodesOfType($type) as $node) {
                if (null !== $literal = $document->stringLiteral($node)) {
                    $literals[] = ['parent' => $node->parent, 'literal' => $literal];
                }
            }
        }

        $calls = [];
        foreach ($document->nodesOfType('filter') as $filter) {
            $identifier = $document->directChild($filter, 'filter_identifier');
            if (null === $identifier || 'trans' !== $document->text($identifier)) {
                continue;
            }
            $key = $this->filteredLiteral($masked, $filter, $literals);
            if (null === $key) {
                continue;
            }
            $arguments = $this->arguments->resolve($document, $filter);
            $calls[] = [
                'key' => $key,
                'domain' => $arguments->get(1, 'domain'),
                'parameters' => $arguments->get(0, 'arguments', 'parameters'),
            ];
        }

        return $calls;
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

    /** @return list<array{key: TwigStringLiteral, domain: ?TreeSitterNode, parameters: ?TreeSitterNode}> */
    private function functionCalls(TwigDocument $document): array
    {
        $calls = [];
        foreach ($document->nodesOfType('function_call') as $call) {
            $identifier = $document->directChild($call, 'function_identifier');
            if (null === $identifier || !\in_array($document->text($identifier), ['trans', 't'], true)) {
                continue;
            }
            $arguments = $this->arguments->resolve($document, $call);
            $keyArgument = $arguments->get(0, 'id', 'message');
            $key = null === $keyArgument ? null : $document->soleStringLiteral($keyArgument);
            if (null === $key) {
                continue;
            }
            $calls[] = [
                'key' => $key,
                'domain' => $arguments->get(2, 'domain'),
                'parameters' => $arguments->get(1, 'arguments', 'parameters'),
            ];
        }

        return $calls;
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
