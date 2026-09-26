<?php

namespace Symfony\Lsp\Tests\Parser\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCall;
use Symfony\Lsp\Parser\Twig\TwigCallArgument;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Parser\Twig\TwigDocument;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TwigDocumentCallsTest extends TestCase
{
    public function testReturnsFunctionAndFilterCallsInSourceOrder(): void
    {
        $document = $this->parse("{{ path('home')|upper }}{% set label = 'key'|trans %}{{ url('about') }}");

        self::assertSame(
            [['path', false], ['upper', true], ['trans', true], ['url', false]],
            array_map(static fn (TwigCall $call): array => [$call->name, $call->filter], $document->calls()),
        );
        self::assertSame(['path', 'url'], array_map(static fn (TwigCall $call): string => $call->name, $document->calls('url', 'path')));
        self::assertSame([], $document->calls('missing'));
        self::assertSame(['path', 'url'], array_map(static fn (TwigCall $call): string => $call->name, $document->functions()));
        self::assertSame(['upper', 'trans'], array_map(static fn (TwigCall $call): string => $call->name, $document->filters()));
        self::assertSame([], $document->functions('trans'));
        self::assertSame([], $document->filters('path'));
    }

    public function testResolvesPositionalAndNamedArguments(): void
    {
        $source = <<<'TWIG'
            {{ call('first', {}, 'third') }}
            {{ call(parameters: {}, # vérifié
                message = 'named') }}
            TWIG;
        [$positional, $named] = $this->parse($source)->calls('call');

        self::assertSame('first', $positional->argument(0, 'message')?->literal()?->value);
        self::assertSame('{}', $this->text($source, $positional->argument(1)));
        self::assertSame('third', $positional->argument(2, 'domain')?->literal()?->value);
        self::assertNull($positional->argument(3));
        self::assertSame([], $positional->namedArguments());

        self::assertSame('named', $named->argument(0, 'message')?->literal()?->value);
        self::assertSame('message', $named->argument(0, 'id', 'message')?->name);
        self::assertSame('{}', $this->text($source, $named->argument(1, 'parameters')));
        self::assertNull($named->argument(0));
        self::assertSame([
            ['name' => 'parameters', 'offset' => strpos($source, 'parameters')],
            ['name' => 'message', 'offset' => strpos($source, 'message')],
        ], $named->namedArguments());
    }

    public function testPipedValueIsTheFirstFilterArgument(): void
    {
        $source = <<<'TWIG'
            {{ 'key' # note
                | trans({}, domain: 'admin') }}
            {{ 'lower'|lower|trans }}
            {{ ('a' ~ 'b')|trans }}
            {{ name|trans('ignored') }}
            {{ trans }}
            TWIG;
        [$filter, $chained, $grouped, $variable] = $this->parse($source)->calls('trans');

        self::assertTrue($filter->filter);
        self::assertSame('key', $filter->argument(0, 'message')?->literal()?->value);
        self::assertSame('{}', $this->text($source, $filter->argument(1, 'arguments')));
        self::assertSame('admin', $filter->argument(2, 'domain')?->literal()?->value);
        self::assertNull($chained->argument(0));
        self::assertNull($grouped->argument(0)?->literal());
        self::assertSame('name', $this->text($source, $variable->argument(0)));
        self::assertNull($variable->argument(0)?->literal());
        self::assertSame('ignored', $variable->argument(1)?->literal()?->value);
    }

    public function testResolvesNestedCalls(): void
    {
        $source = "{{ path('outer', {slug: url('inner')|trim, label: 'key'|trans}) }}";
        $document = $this->parse($source);

        self::assertSame('outer', $document->calls('path')[0]->argument(0)?->literal()?->value);
        self::assertSame('inner', $document->calls('url')[0]->argument(0)?->literal()?->value);
        self::assertSame("url('inner')", $this->text($source, $document->calls('trim')[0]->argument(0)));
        self::assertSame('key', $document->calls('trans')[0]->argument(0)?->literal()?->value);
    }

    public function testDecodesEscapedAndMultibyteLiterals(): void
    {
        $source = <<<'TWIG'
            {{ call('it\'s', "say \"héllo\"\n", "#{interpolated}", 'caf' ~ 'é') }}
            TWIG;
        $call = $this->parse($source)->calls('call')[0];

        $escaped = $call->argument(0)?->literal();
        self::assertNotNull($escaped);
        self::assertSame("it's", $escaped->value);
        self::assertSame(strpos($source, 'it'), $escaped->startOffset);
        $multibyte = $call->argument(1)?->literal();
        self::assertNotNull($multibyte);
        self::assertSame("say \"héllo\"\n", $multibyte->value);
        self::assertSame(strpos($source, 'say'), $multibyte->startOffset);
        self::assertSame(strpos($source, '", "#{'), $multibyte->endOffset);
        self::assertNull($call->argument(2)?->literal());
        self::assertNull($call->argument(3)?->literal());
    }

    public function testToleratesUnterminatedInput(): void
    {
        $document = $this->parse("{{ path('home') }}\n{{ path('unterminated");

        self::assertSame(['home'], array_map(static fn (TwigCall $call): ?string => $call->argument(0)?->literal()?->value, $document->calls('path')));
        self::assertSame([], $this->parse("{{ 'open|trans({}, 'x")->calls('trans'));
    }

    private function text(string $source, ?TwigCallArgument $argument): ?string
    {
        return null === $argument ? null : substr($source, $argument->node->startByte, $argument->node->endByte - $argument->node->startByte);
    }

    private function parse(string $source): TwigDocument
    {
        return (new TwigDocumentParser(
            new NativeTreeSitterParser(new TreeSitterResultDecoder()),
            new TwigCommentParser(),
            new TwigDirectiveLocator(),
        ))->parse($source);
    }
}
