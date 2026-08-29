<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use Symfony\Lsp\Tools\Dogfood\ProcessResult;
use Symfony\Lsp\Tools\Dogfood\ProcessRunnerInterface;

final class FakeProcessRunner implements ProcessRunnerInterface
{
    /** @var list<array{command: list<string>, directory: ?string, timeout: float, environment: array<string, string>}> */
    public array $calls = [];

    /**
     * @param \Closure(list<string>, ?string, array<string, string>): ProcessResult $handler
     */
    public function __construct(
        private \Closure $handler,
    ) {
    }

    public function run(array $command, ?string $directory = null, float $timeout = 600.0, array $environment = []): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'directory' => $directory, 'timeout' => $timeout, 'environment' => $environment];

        return ($this->handler)($command, $directory, $environment);
    }
}
