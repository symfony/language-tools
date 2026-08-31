<?php

namespace Symfony\Lsp\Check;

use Amp\Cancellation;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\TimeoutCancellation;

use function Amp\delay;

final class CheckRunCancellation
{
    private readonly DeferredCancellation $signal;
    private readonly TimeoutCancellation $timeout;
    private readonly Cancellation $cancellation;
    private bool $timedOut = false;

    public function __construct(
        public readonly float $deadline,
        public readonly float $timeoutSeconds,
    ) {
        $this->signal = new DeferredCancellation();
        $this->timeout = new TimeoutCancellation($timeoutSeconds);
        $this->cancellation = new CompositeCancellation($this->timeout, $this->signal->getCancellation());
    }

    public function cancellation(): Cancellation
    {
        return $this->cancellation;
    }

    public function signal(): DeferredCancellation
    {
        return $this->signal;
    }

    public function checkpoint(): void
    {
        $this->expire();
        $this->cancellation->throwIfRequested();
    }

    public function yieldIfNeeded(int $count, int $interval = 64): void
    {
        if (0 === $count % $interval) {
            delay(0, cancellation: $this->cancellation);
        }
    }

    public function timedOut(): bool
    {
        return $this->timedOut || $this->timeout->isRequested();
    }

    public function expired(): bool
    {
        return microtime(true) >= $this->deadline;
    }

    private function expire(): void
    {
        if (!$this->timedOut && $this->expired()) {
            $this->timedOut = true;
            $this->signal->cancel();
        }
    }
}
