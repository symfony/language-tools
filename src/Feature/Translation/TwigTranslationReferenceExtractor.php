<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\Twig\TwigCallArgument;
use Symfony\Lsp\Parser\Twig\TwigDocument;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Parser\Twig\TwigStringLiteral;

final class TwigTranslationReferenceExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigDocumentParser $parser,
        private readonly TranslationParameterAnalyzer $parameters,
    ) {
    }

    /** @return list<TranslationReference> */
    public function extract(string $uri, string $text): array
    {
        $document = $this->parser->parse($text);
        $defaultDomain = $this->defaultDomain($document);

        $references = [];
        foreach ($this->calls($document) as $call) {
            $domain = $this->domain($call['domain'], $defaultDomain);
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

        return [...$references, ...$this->tagReferences($document, $uri, $text, $defaultDomain)];
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
        foreach ($this->calls($document) as $call) {
            if ($offset >= $call['key']->startOffset && $offset <= $call['key']->endOffset) {
                return $this->domain($call['domain'], $defaultDomain);
            }
        }

        return $defaultDomain;
    }

    /** @return list<array{key: TwigStringLiteral, domain: ?TwigCallArgument, parameters: ?TreeSitterNode}> */
    private function calls(TwigDocument $document): array
    {
        $calls = [];
        foreach ([...$document->filters('trans'), ...$document->functions('trans', 't')] as $call) {
            $key = $call->argument(0, 'id', 'message')?->literal();
            if (null !== $key) {
                $calls[] = [
                    'key' => $key,
                    'domain' => $call->argument(2, 'domain'),
                    'parameters' => $call->argument(1, 'arguments', 'parameters')?->node,
                ];
            }
        }

        return $calls;
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

    private function domain(?TwigCallArgument $argument, string $defaultDomain): ?string
    {
        return null === $argument ? $defaultDomain : $argument->literal()?->value;
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
    private function tagReferences(TwigDocument $document, string $uri, string $text, string $defaultDomain): array
    {
        $references = [];
        $open = null;
        foreach ($document->nodesOfType('statement_directive') as $directive) {
            $statement = $document->firstDescendant($directive, 'tag_statement');
            $tag = null === $statement ? null : $document->directChild($statement, 'tag');
            $name = null === $tag ? null : $document->text($tag);
            if ('trans' === $name) {
                $open = [$directive->endByte, $this->tagDomain($document, $statement) ?? $defaultDomain];
                continue;
            }
            if ('endtrans' !== $name || null === $open) {
                continue;
            }
            [$bodyOffset, $domain] = $open;
            $open = null;
            $body = substr($text, $bodyOffset, $directive->startByte - $bodyOffset);
            $key = trim($body);
            if ('' === $key) {
                continue;
            }
            $offset = $bodyOffset + \strlen($body) - \strlen(ltrim($body));
            $references[] = new TranslationReference($key, $domain, $uri, $this->converter->toRange($text, $offset, \strlen($key)));
        }

        return $references;
    }

    private function tagDomain(TwigDocument $document, TreeSitterNode $statement): ?string
    {
        $fromKeyword = false;
        foreach ($document->children($statement) as $child) {
            if ($fromKeyword && null !== $literal = $document->stringLiteral($child)) {
                return $literal->value;
            }
            $fromKeyword = 'variable' === $child->type && 'from' === $document->text($child);
        }

        return null;
    }
}
