<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TwigCallableReferenceExtractor
{
    public function __construct(
        private readonly TwigDocumentParser $parser,
        private readonly TwigCommentParser $commentParser,
        private readonly PositionConverter $converter,
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
    public function all(string $uri, string $text): array
    {
        $document = $this->parser->parse($text);
        $masked = $this->commentParser->mask($text);
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
                    $uri,
                    new Range(
                        $this->converter->toPosition($text, $identifier->startByte),
                        $this->converter->toPosition($text, $identifier->endByte),
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
        $close = null;
        $quote = null;
        $escaped = false;
        $brackets = [];
        for ($cursor = 0; $cursor < $offset; ++$cursor) {
            $character = $text[$cursor];
            $pair = substr($text, $cursor, 2);
            if (null === $close) {
                if ('{{' === $pair) {
                    $close = '}}';
                    $brackets = [];
                    ++$cursor;
                } elseif ('{%' === $pair) {
                    $close = '%}';
                    $brackets = [];
                    ++$cursor;
                }
                continue;
            }
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }
                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
            } elseif (\in_array($character, ['(', '[', '{'], true)) {
                $brackets[] = ['(' => ')', '[' => ']', '{' => '}'][$character];
            } elseif ([] !== $brackets && $character === $brackets[array_key_last($brackets)]) {
                array_pop($brackets);
            } elseif ([] === $brackets && $close === $pair) {
                $close = null;
                ++$cursor;
            }
        }

        return null !== $close;
    }
}
