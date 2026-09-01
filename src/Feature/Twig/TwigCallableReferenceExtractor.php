<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TwigCallableReferenceExtractor
{
    public function __construct(
        private readonly TwigDocumentParser $parser,
        private readonly TwigCommentParser $commentParser,
        private readonly PositionConverter $converter,
        private readonly TwigDirectiveLocator $directives,
    ) {
    }

    public function at(string $text, int $offset): ?TwigCallableReference
    {
        $document = $this->parser->parse($text);
        $masked = $this->commentParser->mask($text);
        foreach ([
            ['function_call', 'function_identifier', TwigCallableKind::Function],
            ['filter', 'filter_identifier', TwigCallableKind::Filter],
        ] as [$containerType, $identifierType, $kind]) {
            foreach ($document->nodesOfType($containerType) as $container) {
                $identifier = $document->directChild($container, $identifierType);
                if (null === $identifier || !$this->contains($identifier, $offset) || !$this->insideDirective($masked, $identifier->startByte)) {
                    continue;
                }
                $name = $document->text($identifier);
                if (1 !== preg_match('/^[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*$/', $name)) {
                    continue;
                }

                return new TwigCallableReference($kind, $name);
            }
        }

        return null;
    }

    /** @return list<TwigCallableUsage> */
    public function all(SourceDocument $source): array
    {
        $document = $this->parser->parse($source->text);
        $masked = $this->commentParser->mask($source->text);
        $usages = [];
        foreach ([
            ['function_call', 'function_identifier', TwigCallableKind::Function],
            ['filter', 'filter_identifier', TwigCallableKind::Filter],
        ] as [$containerType, $identifierType, $kind]) {
            foreach ($document->nodesOfType($containerType) as $container) {
                $identifier = $document->directChild($container, $identifierType);
                if (null === $identifier || !$this->insideDirective($masked, $identifier->startByte)) {
                    continue;
                }
                $name = $document->text($identifier);
                if (1 !== preg_match('/^[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*$/', $name)) {
                    continue;
                }
                $usages[$identifier->startByte] = new TwigCallableUsage(
                    $kind,
                    $name,
                    $source->uri,
                    new Range(
                        $this->converter->toPosition($source->text, $identifier->startByte),
                        $this->converter->toPosition($source->text, $identifier->endByte),
                    ),
                );
            }
        }
        ksort($usages);

        return array_values($usages);
    }

    private function contains(TreeSitterNode $node, int $offset): bool
    {
        return $offset >= $node->startByte && $offset <= $node->endByte;
    }

    public function insideDirective(string $text, int $offset): bool
    {
        return $this->directives->insideDirective($text, $offset);
    }
}
