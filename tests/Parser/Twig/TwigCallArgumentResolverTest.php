<?php

namespace Symfony\Lsp\Tests\Parser\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigArgumentParser;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TwigCallArgumentResolverTest extends TestCase
{
    public function testResolvesNamedAndPositionalArguments(): void
    {
        $source = <<<'TWIG'
            {{ call(parameters: {}, # vérifié
                message = 'named.key') }}
            {{ call('positional.key', {}, 'admin') }}
            TWIG;
        $comments = new TwigCommentParser();
        $document = (new TwigDocumentParser(
            new NativeTreeSitterParser(new TreeSitterResultDecoder()),
            $comments,
        ))->parse($source);
        $resolver = new TwigCallArgumentResolver(new TwigArgumentParser());
        $calls = $document->nodesOfType('function_call');

        $named = $resolver->resolve($document, $calls[0]);
        $message = $named->get(0, 'message');
        $parameters = $named->get(1, 'parameters');
        self::assertSame("'named.key'", null === $message ? null : $document->text($message));
        self::assertSame('{}', null === $parameters ? null : $document->text($parameters));

        $positional = $resolver->resolve($document, $calls[1]);
        $message = $positional->get(0);
        $parameters = $positional->get(1);
        $domain = $positional->get(2);
        self::assertSame("'positional.key'", null === $message ? null : $document->text($message));
        self::assertSame('{}', null === $parameters ? null : $document->text($parameters));
        self::assertSame("'admin'", null === $domain ? null : $document->text($domain));
    }
}
