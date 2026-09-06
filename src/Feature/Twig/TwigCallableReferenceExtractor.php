<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Parser\Twig\TwigDocument;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TwigCallableReferenceExtractor
{
    public function __construct(
        private readonly TwigDocumentParser $parser,
        private readonly TwigCommentParser $commentParser,
        private readonly PositionConverter $converter,
        private readonly TwigDirectiveLocator $directives,
        private readonly TwigCallArgumentResolver $arguments,
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
                if (!$this->validName($name)) {
                    continue;
                }

                return new TwigCallableReference($kind, $name);
            }
        }

        return null;
    }

    public function extract(SourceDocument $source): TwigCallableSourceFacts
    {
        $document = $this->parser->parse($source->text);
        $usages = [];
        $calls = [];
        foreach ($this->callableNodes($document, $this->directives->ranges($document->maskedSource())) as [$container, $identifier, $kind]) {
            $name = $document->text($identifier);
            if (!$this->validName($name)) {
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

            $argumentContainer = $document->directChild($container, 'arguments');
            if (null === $argumentContainer || !str_ends_with($document->maskedText($argumentContainer), ')')) {
                continue;
            }
            $arguments = [];
            foreach ($this->arguments->resolve($document, $container)->named() as $argument) {
                $arguments[] = new TwigCallableArgumentReference(
                    $argument['name'],
                    $this->converter->toRange($source->text, $argument['offset'], \strlen($argument['name'])),
                );
            }
            if ([] !== $arguments) {
                $calls[$container->startByte] = [
                    'end' => $container->endByte,
                    'reference' => new TwigCallableCallReference($kind, $name, $arguments),
                ];
            }
        }
        ksort($usages);
        usort($calls, static fn (array $left, array $right): int => $left['end'] <=> $right['end']);

        return new TwigCallableSourceFacts(
            $source->uri,
            [],
            array_values($usages),
            array_column($calls, 'reference'),
        );
    }

    /**
     * @param list<array{start: int, end: int}> $directiveRanges
     *
     * @return list<array{TreeSitterNode, TreeSitterNode, TwigCallableKind}>
     */
    private function callableNodes(TwigDocument $document, array $directiveRanges): array
    {
        $nodes = [];
        foreach ([
            ['function_call', 'function_identifier', TwigCallableKind::Function],
            ['filter', 'filter_identifier', TwigCallableKind::Filter],
        ] as [$containerType, $identifierType, $kind]) {
            foreach ($document->nodesOfType($containerType) as $container) {
                $identifier = $document->directChild($container, $identifierType);
                if (null === $identifier) {
                    continue;
                }
                $arguments = $document->directChild($container, 'arguments');
                if (null !== $arguments && '' !== trim(substr($document->maskedText($container), $identifier->endByte - $container->startByte, $arguments->startByte - $identifier->endByte))) {
                    continue;
                }
                $nodes[] = [$container, $identifier, $kind];
            }
        }
        usort($nodes, static fn (array $left, array $right): int => [$left[0]->startByte, $left[0]->endByte] <=> [$right[0]->startByte, $right[0]->endByte]);

        $inside = [];
        $rangeIndex = 0;
        foreach ($nodes as $node) {
            while (isset($directiveRanges[$rangeIndex]) && $directiveRanges[$rangeIndex]['end'] <= $node[1]->startByte) {
                ++$rangeIndex;
            }
            if (isset($directiveRanges[$rangeIndex]) && $directiveRanges[$rangeIndex]['start'] <= $node[1]->startByte) {
                $inside[] = $node;
            }
        }

        return $inside;
    }

    private function contains(TreeSitterNode $node, int $offset): bool
    {
        return $offset >= $node->startByte && $offset <= $node->endByte;
    }

    public function insideDirective(string $text, int $offset): bool
    {
        return $this->directives->insideDirective($text, $offset);
    }

    private function validName(string $name): bool
    {
        return 1 === preg_match('/^[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*$/', $name);
    }
}
