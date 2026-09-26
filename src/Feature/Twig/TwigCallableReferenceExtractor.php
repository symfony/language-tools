<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\Twig\TwigCall;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Parser\Twig\TwigDocument;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TwigCallableReferenceExtractor
{
    public function __construct(
        private readonly TwigDocumentParser $parser,
        private readonly PositionConverter $converter,
        private readonly TwigDirectiveLocator $directives,
    ) {
    }

    public function at(string $text, int $offset): ?TwigCallableReference
    {
        $document = $this->parser->parse($text);
        $masked = $document->maskedSource();
        foreach ($document->calls() as $call) {
            if ($this->contains($call->identifier, $offset) && $this->insideDirective($masked, $call->identifier->startByte) && $this->validName($call->name)) {
                return new TwigCallableReference($this->kind($call), $call->name);
            }
        }

        return null;
    }

    public function extract(SourceDocument $source): TwigCallableSourceFacts
    {
        $document = $this->parser->parse($source->text);
        $usages = [];
        $calls = [];
        foreach ($this->directiveCalls($document, $this->directives->ranges($document->maskedSource())) as $call) {
            $name = $call->name;
            if (!$this->validName($name)) {
                continue;
            }
            $container = $call->node;
            $identifier = $call->identifier;
            $kind = $this->kind($call);
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
            if (null === $argumentContainer
                || '' !== trim(substr($document->maskedText($container), $identifier->endByte - $container->startByte, $argumentContainer->startByte - $identifier->endByte))
                || !str_ends_with($document->maskedText($argumentContainer), ')')
            ) {
                continue;
            }
            $arguments = [];
            foreach ($call->namedArguments() as $argument) {
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
     * @return list<TwigCall>
     */
    private function directiveCalls(TwigDocument $document, array $directiveRanges): array
    {
        $inside = [];
        $rangeIndex = 0;
        foreach ($document->calls() as $call) {
            while (isset($directiveRanges[$rangeIndex]) && $directiveRanges[$rangeIndex]['end'] <= $call->identifier->startByte) {
                ++$rangeIndex;
            }
            if (isset($directiveRanges[$rangeIndex]) && $directiveRanges[$rangeIndex]['start'] <= $call->identifier->startByte) {
                $inside[] = $call;
            }
        }

        return $inside;
    }

    private function kind(TwigCall $call): TwigCallableKind
    {
        return $call->filter ? TwigCallableKind::Filter : TwigCallableKind::Function;
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
