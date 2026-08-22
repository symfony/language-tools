<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use Symfony\Lsp\Tools\Dogfood\ProcessResult;
use Symfony\Lsp\Tools\Dogfood\ProcessRunnerInterface;

final class FakeProcessRunner implements ProcessRunnerInterface
{
    /** @var list<array{command: list<string>, directory: ?string, timeout: float}> */
    public array $calls = [];

    /**
     * @param \Closure(list<string>, ?string): ProcessResult $handler
     */
    public function __construct(
        private \Closure $handler,
    ) {
    }

    public function run(array $command, ?string $directory = null, float $timeout = 600.0): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'directory' => $directory, 'timeout' => $timeout];

        return ($this->handler)($command, $directory);
    }
}
