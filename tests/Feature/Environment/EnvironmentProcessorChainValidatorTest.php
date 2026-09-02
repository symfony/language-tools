<?php

namespace Symfony\Lsp\Tests\Feature\Environment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Environment\EnvironmentIndex;
use Symfony\Lsp\Feature\Environment\EnvironmentProcessorChainValidator;

final class EnvironmentProcessorChainValidatorTest extends TestCase
{
    /** @param list<string> $chain */
    #[DataProvider('validChainProvider')]
    public function testAcceptsProcessorArguments(array $chain): void
    {
        $index = new EnvironmentIndex();
        $index->replaceProcessors(['custom' => 'string', 'default' => 'string', 'json' => 'array']);

        self::assertSame([], (new EnvironmentProcessorChainValidator())->validate($chain, $index));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function validChainProvider(): iterable
    {
        yield 'built-in argument processor' => [['default', 'fallback_parameter']];
        yield 'custom processor argument' => [['custom', 'option']];
    }

    public function testReportsArgumentProcessorWithoutAnArgumentAndVariable(): void
    {
        $index = new EnvironmentIndex();
        $index->replaceProcessors(['default' => 'string']);

        $issues = (new EnvironmentProcessorChainValidator())->validate(['default'], $index);

        self::assertSame(['env.malformed_chain'], array_map(static fn ($issue): string => $issue->code, $issues));
        self::assertSame('Environment processor "default" requires an argument followed by an environment variable.', $issues[0]->message);
    }

    public function testReportsMalformedAndUnknownProcessorSegments(): void
    {
        $index = new EnvironmentIndex();
        $index->replaceProcessors(['custom' => 'string', 'json' => 'array']);

        $issues = (new EnvironmentProcessorChainValidator())->validate(['', 'json', 'option', 'custom', 'argument', 'extra'], $index);

        self::assertSame(['env.malformed_chain', 'env.unknown_processor', 'env.unknown_processor'], array_map(static fn ($issue): string => $issue->code, $issues));
        self::assertSame('Environment processor "option" is not installed.', $issues[1]->message);
        self::assertSame('Environment processor "extra" is not installed.', $issues[2]->message);
    }
}
