<?php

namespace Symfony\Lsp\Tests\Parser\Php;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Php\PhpArgumentCursor;
use Symfony\Lsp\Parser\Php\PhpArgumentList;
use Symfony\Lsp\Parser\Php\PhpAttribute;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpObjectCreation;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;

final class PhpArgumentCursorTest extends TestCase
{
    /**
     * @param array{call: string, position: int, name: ?string, quote: ?string, prefix: string, raw: ?string, argumentLiteral: bool, arrayItemLiteral: bool}|null $expected
     */
    #[DataProvider('cursorProvider')]
    public function testLocatesTheArgumentAtTheCursor(string $source, ?array $expected): void
    {
        $offset = strpos($source, '|');
        self::assertIsInt($offset);
        $source = str_replace('|', '', $source);
        $document = $this->parse($source);

        $cursor = $document->argumentCursorAt($offset);

        if (null === $expected) {
            self::assertNull($cursor);

            return;
        }
        self::assertNotNull($cursor);
        self::assertSame($expected['call'], $this->callName($cursor));
        self::assertSame($expected['position'], $cursor->position);
        self::assertSame($expected['name'], $cursor->name);
        self::assertSame($expected['quote'], $cursor->quote);
        self::assertSame($expected['prefix'], $cursor->prefix);
        self::assertSame($expected['argumentLiteral'], $cursor->isArgumentLiteral());
        self::assertSame($expected['arrayItemLiteral'], $cursor->isArrayItemLiteral());
        self::assertSame($expected['raw'] ?? $expected['prefix'], substr($source, $cursor->prefixStartOffset, $offset - $cursor->prefixStartOffset));
    }

    /**
     * @return iterable<string, array{string, array{call: string, position: int, name: ?string, quote: ?string, prefix: string, raw: ?string, argumentLiteral: bool, arrayItemLiteral: bool}|null}>
     */
    public static function cursorProvider(): iterable
    {
        yield 'method call' => ["<?php \$translator->trans('app.ti|tle');", self::cursor('trans', prefix: 'app.ti')];
        yield 'incomplete trailing source' => ["<?php \$translator->trans('app.ti|", self::cursor('trans', prefix: 'app.ti')];
        yield 'named argument' => ["<?php \$translator->trans(id: 'app.ti|", self::cursor('trans', name: 'id', prefix: 'app.ti')];
        yield 'later argument' => ["<?php \$translator->trans('app.title', [], 'admi|", self::cursor('trans', position: 2, prefix: 'admi')];
        yield 'object creation' => ["<?php new TranslatableMessage('app.ti|", self::cursor('TranslatableMessage', prefix: 'app.ti')];
        yield 'attribute' => ["<?php #[Route('/blog', name: 'app_bl|", self::cursor('Route', position: 1, name: 'name', prefix: 'app_bl')];
        yield 'array item' => ["<?php \$repository->findBy(['ti|", self::cursor('findBy', prefix: 'ti', argumentLiteral: false, arrayItemLiteral: true)];
        yield 'array value' => ["<?php \$repository->findBy(['title' => 'Sym|", self::cursor('findBy', prefix: 'Sym', argumentLiteral: false)];
        yield 'array item after a complete entry' => ["<?php \$repository->findBy(['title' => 1, 'sl|", self::cursor('findBy', prefix: 'sl', argumentLiteral: false, arrayItemLiteral: true)];
        yield 'legacy array item' => ["<?php \$repository->findBy(array('ti|", self::cursor('findBy', prefix: 'ti', argumentLiteral: false, arrayItemLiteral: true)];
        yield 'function call item' => ["<?php \$repository->findBy(compact('ti|", self::cursor('findBy', prefix: 'ti', argumentLiteral: false)];
        yield 'array in a ternary' => ["<?php \$repository->findBy(\$all ? ['ti|", self::cursor('findBy', prefix: 'ti', argumentLiteral: false)];
        yield 'array after an operator' => ["<?php \$repository->findBy(['a'] + ['ti|", self::cursor('findBy', prefix: 'ti', argumentLiteral: false)];
        yield 'legacy array in a ternary' => ["<?php \$repository->findBy(\$all ? array('ti|", self::cursor('findBy', prefix: 'ti', argumentLiteral: false)];
        yield 'nested array item' => ["<?php \$builder->add('name', Type::class, ['attr' => ['cla|", self::cursor('add', position: 2, prefix: 'cla', argumentLiteral: false)];
        yield 'innermost call' => ["<?php \$this->render('page.html.twig', ['form' => \$this->createForm(Type::class, null, ['lab|", self::cursor('createForm', position: 2, prefix: 'lab', argumentLiteral: false, arrayItemLiteral: true)];
        yield 'escaped quote' => ["<?php \$input->getOption('out\\'pu|", self::cursor('getOption', prefix: "out'pu", raw: "out\\'pu")];
        yield 'escaped double quote' => ['<?php $input->getOption("out\\"pu|', self::cursor('getOption', quote: '"', prefix: 'out"pu', raw: 'out\\"pu')];
        yield 'multibyte text' => ["<?php \$translator->trans('app.crème brûlé|", self::cursor('trans', prefix: 'app.crème brûlé')];
        yield 'empty prefix' => ["<?php \$input->getOption('|", self::cursor('getOption', prefix: '')];
        yield 'interpolated literal' => ['<?php $input->getOption("out$name|', self::cursor('getOption', quote: null, prefix: '', argumentLiteral: false)];
        yield 'escaped dollar' => ['<?php $input->getOption("out\\$na|', self::cursor('getOption', quote: '"', prefix: 'out$na', raw: 'out\\$na')];
        yield 'concatenated literal' => ["<?php \$input->getOption('out' . 'pu|", self::cursor('getOption', prefix: 'pu', argumentLiteral: false)];
        yield 'literal followed by a concatenation' => ["<?php \$input->getOption('ou|' . \$x);", self::cursor('getOption', prefix: 'ou', argumentLiteral: false)];
        yield 'literal concatenated with an open literal' => ["<?php \$input->getOption('ou|' . 'x", self::cursor('getOption', prefix: 'ou', argumentLiteral: false)];
        yield 'literal before a dangling operator' => ["<?php \$input->getOption('ou|' .", self::cursor('getOption', prefix: 'ou', argumentLiteral: false)];
        yield 'literal before a later argument' => ["<?php \$translator->trans('app.ti|', [], 'admin');", self::cursor('trans', prefix: 'app.ti')];
        yield 'variable argument' => ['<?php $input->getOption($na|', self::cursor('getOption', quote: null, prefix: '', argumentLiteral: false)];
        yield 'closed literal' => ["<?php \$input->getOption('out'|);", self::cursor('getOption', quote: null, prefix: '', argumentLiteral: false)];
        yield 'static call' => ["<?php Translator::trans('app.ti|", null];
        yield 'after the call' => ["<?php \$input->getOption('out')|;", null];
        yield 'plain function call' => ["<?php t('app.ti|", null];
        yield 'commented call' => ["<?php // \$input->getOption('out|", null];
        yield 'trailing comment' => ["<?php \$repository->findBy([ // 'ti|", null];
        yield 'heredoc' => ["<?php \$translator->trans(<<<EOT\n    app.title\n    EOT, [], 'admi|", self::cursor('trans', position: 2, prefix: 'admi')];
    }

    public function testLocatesTheCursorInAStandaloneArgumentList(): void
    {
        $source = "<?php \$translator->trans('app.ti";
        $document = $this->parse($source);
        $arguments = $document->methodCalls[0]->arguments;

        $cursor = PhpArgumentCursor::at(new PhpArgumentList($arguments), \strlen($source));

        self::assertSame('app.ti', $cursor?->prefix);
        self::assertTrue($cursor->isArgumentLiteral());
    }

    /**
     * @return array{call: string, position: int, name: ?string, quote: ?string, prefix: string, raw: ?string, argumentLiteral: bool, arrayItemLiteral: bool}
     */
    private static function cursor(string $call, int $position = 0, ?string $name = null, ?string $quote = "'", string $prefix = '', ?string $raw = null, bool $argumentLiteral = true, bool $arrayItemLiteral = false): array
    {
        return [
            'call' => $call,
            'position' => $position,
            'name' => $name,
            'quote' => $quote,
            'prefix' => $prefix,
            'raw' => $raw,
            'argumentLiteral' => $argumentLiteral,
            'arrayItemLiteral' => $arrayItemLiteral,
        ];
    }

    private function callName(PhpArgumentCursor $cursor): string
    {
        $call = $cursor->call;

        return match (true) {
            $call instanceof PhpMethodCall => $call->method,
            $call instanceof PhpObjectCreation => $call->className,
            $call instanceof PhpAttribute => $call->name,
            default => '',
        };
    }

    private function parse(string $source): PhpDocument
    {
        return (new TolerantPhpParser(new Parser()))->parse($source);
    }
}
