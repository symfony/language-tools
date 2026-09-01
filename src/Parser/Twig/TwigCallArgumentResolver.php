<?php

namespace Symfony\Lsp\Parser\Twig;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;

final class TwigCallArgumentResolver
{
    public function __construct(private readonly TwigArgumentParser $parser)
    {
    }

    public function resolve(TwigDocument $document, TreeSitterNode $call): TwigCallArguments
    {
        $container = $document->directChild($call, 'arguments');
        if (null === $container) {
            return new TwigCallArguments([]);
        }

        $text = $document->maskedText($container);
        $offset = $container->startByte;
        if (str_starts_with($text, '(')) {
            $text = substr($text, 1);
            ++$offset;
        }
        if (str_ends_with($text, ')')) {
            $text = substr($text, 0, -1);
        }

        $parsed = $this->parser->parse($text, $offset);
        $arguments = [];
        foreach ($document->children($container) as $child) {
            if ('argument' !== $child->type) {
                continue;
            }
            $value = $document->directChild($child, 'argument_value') ?? $child;
            $name = null;
            foreach ($parsed as $argument) {
                if ($value->startByte >= $argument->offset && $value->startByte < $argument->offset + \strlen($argument->text)) {
                    $name = $argument->name;

                    break;
                }
            }
            $arguments[] = ['name' => $name, 'value' => $value];
        }

        return new TwigCallArguments($arguments);
    }
}
