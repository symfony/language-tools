<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\DependencyInjection\PhpTargetAttributeResolver;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;

final class PhpTargetAttributeResolverTest extends TestCase
{
    /** @param list<string> $types */
    #[DataProvider('completionProvider')]
    public function testResolvesTheTargetArgumentHoldingTheCursor(string $body, string $cursorAfter, string $value, array $types): void
    {
        $text = $this->php($body);
        $cursor = strrpos($text, $cursorAfter);
        self::assertIsInt($cursor);
        $cursor += \strlen($cursorAfter);

        $argument = $this->resolver()->resolve($text, $cursor);

        self::assertNotNull($argument);
        self::assertSame($value, $argument->value);
        self::assertSame($types, $argument->types);
        self::assertSame($cursor, $argument->cursorOffset());
    }

    /** @return iterable<string, array{string, string, string, list<string>}> */
    public static function completionProvider(): iterable
    {
        yield 'positional argument' => [
            "final class S { public function __construct(#[Target('http_')] LoggerInterface \$logger) {} }",
            "Target('http_",
            'http_',
            ['Psr\\Log\\LoggerInterface'],
        ];
        yield 'named argument' => [
            'final class S { public function __construct(#[Target(name: "app")] LoggerInterface $logger) {} }',
            'name: "app',
            'app',
            ['Psr\\Log\\LoggerInterface'],
        ];
        yield 'promoted constructor parameter' => [
            "final class S { public function __construct(#[Target('app')] private readonly LoggerInterface \$logger) {} }",
            "Target('app",
            'app',
            ['Psr\\Log\\LoggerInterface'],
        ];
        yield 'fully qualified attribute' => [
            "final class S { public function __construct(#[\\Symfony\\Component\\DependencyInjection\\Attribute\\Target('app')] \\App\\Cache \$cache) {} }",
            "Target('app",
            'app',
            ['App\\Cache'],
        ];
        yield 'nullable type' => [
            "final class S { public function __construct(#[Target('app')] ?LoggerInterface \$logger) {} }",
            "Target('app",
            'app',
            ['Psr\\Log\\LoggerInterface'],
        ];
        yield 'union type' => [
            "final class S { public function __construct(#[Target('')] LoggerInterface|\\App\\Cache \$x) {} }",
            "Target('",
            '',
            ['Psr\\Log\\LoggerInterface', 'App\\Cache'],
        ];
        yield 'closure parameter' => [
            "\$f = function (#[Target('app')] LoggerInterface \$logger) {};",
            "Target('app",
            'app',
            [],
        ];
    }

    #[DataProvider('unresolvedProvider')]
    public function testIgnoresPositionsOutsideATargetString(string $body, string $cursorAfter): void
    {
        $text = $this->php($body);
        $cursor = strrpos($text, $cursorAfter);
        self::assertIsInt($cursor);

        self::assertNull($this->resolver()->resolve($text, $cursor + \strlen($cursorAfter)));
    }

    /** @return iterable<string, array{string, string}> */
    public static function unresolvedProvider(): iterable
    {
        yield 'cursor after the literal' => [
            "final class S { public function __construct(#[Target('app')] LoggerInterface \$logger) {} }",
            "Target('app'",
        ];
        yield 'non-string expression' => [
            'final class S { public function __construct(#[Target(self::NAME)] LoggerInterface $logger) {} }',
            'self::NA',
        ];
        yield 'autowire attribute' => [
            "final class S { public function __construct(#[Autowire(service: 'app')] LoggerInterface \$logger) {} }",
            "service: 'app",
        ];
        yield 'attribute of another namespace' => [
            "final class S { public function __construct(#[\\App\\Target('app')] LoggerInterface \$logger) {} }",
            "Target('app",
        ];
    }

    public function testResolvesTheDecodedLiteralForHover(): void
    {
        $text = $this->php('final class S { public function __construct(#[Target("caf\\u{e9}.\\x41pp")] LoggerInterface $logger) {} }');
        $start = strpos($text, 'caf');
        self::assertIsInt($start);
        $end = strpos($text, '")]');
        self::assertIsInt($end);

        $argument = $this->resolver()->resolveLiteral($text, $start + 2);

        self::assertNotNull($argument);
        self::assertSame('café.App', $argument->name);
        self::assertSame($start, $argument->literalStartOffset);
        self::assertSame($end, $argument->literalEndOffset);
        self::assertSame(['Psr\\Log\\LoggerInterface'], $argument->types);
        self::assertNotNull($this->resolver()->resolveLiteral($text, $end));
        self::assertNull($this->resolver()->resolveLiteral($text, $end + 1));
        self::assertNull($this->resolver()->resolveLiteral($text, $start - 1));
    }

    public function testResolvesMultibyteLiteralForHover(): void
    {
        $text = $this->php("final class S { public function __construct(#[Target('journal.été')] LoggerInterface \$logger) {} }");
        $offset = strpos($text, 'été');
        self::assertIsInt($offset);

        self::assertSame('journal.été', $this->resolver()->resolveLiteral($text, $offset)?->name);
    }

    private function php(string $body): string
    {
        return "<?php\n\nuse Psr\\Log\\LoggerInterface;\nuse Symfony\\Component\\DependencyInjection\\Attribute\\Autowire;\nuse Symfony\\Component\\DependencyInjection\\Attribute\\Target;\n\n".$body;
    }

    private function resolver(): PhpTargetAttributeResolver
    {
        return new PhpTargetAttributeResolver(new TolerantPhpParser(new Parser()));
    }
}
