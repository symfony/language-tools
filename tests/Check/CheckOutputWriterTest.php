<?php

namespace Symfony\Lsp\Tests\Check;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Check\CheckOutputWriter;

final class CheckOutputWriterTest extends TestCase
{
    protected function setUp(): void
    {
        IntermittentWriteStream::$contents = '';
        self::assertTrue(stream_wrapper_register('check-output', IntermittentWriteStream::class));
    }

    protected function tearDown(): void
    {
        stream_wrapper_unregister('check-output');
    }

    public function testRetriesTemporarilyUnavailableAndPartialWrites(): void
    {
        $stream = fopen('check-output://report', 'w');
        self::assertIsResource($stream);
        $contents = str_repeat('diagnostic output ', 1000);

        self::assertTrue((new CheckOutputWriter())->write($stream, $contents));
        fclose($stream);

        self::assertSame($contents, IntermittentWriteStream::$contents);
    }

    public function testWritesLargeReportsWithoutCopyingTheRemainingContents(): void
    {
        $stream = fopen('check-output://discard', 'w');
        self::assertIsResource($stream);
        $contents = str_repeat('x', 8 * 1024 * 1024);
        memory_reset_peak_usage();
        $memory = memory_get_usage();

        $written = (new CheckOutputWriter())->write($stream, $contents);
        $additionalMemory = memory_get_peak_usage() - $memory;
        fclose($stream);

        self::assertTrue($written);
        self::assertLessThan(1024 * 1024, $additionalMemory);
    }
}

final class IntermittentWriteStream
{
    public static string $contents = '';

    /** @var resource|null */
    public $context;

    private bool $unavailable = true;
    private bool $discard = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->discard = 'discard' === parse_url($path, \PHP_URL_HOST);

        return true;
    }

    public function stream_write(string $data): int
    {
        if ($this->discard) {
            return \strlen($data);
        }
        if ($this->unavailable) {
            $this->unavailable = false;

            return 0;
        }

        $chunk = substr($data, 0, 1024);
        self::$contents .= $chunk;

        return \strlen($chunk);
    }

    /** @return array<array-key, mixed> */
    public function stream_stat(): array
    {
        return [];
    }
}
