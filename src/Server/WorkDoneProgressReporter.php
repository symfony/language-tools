<?php

namespace Symfony\Lsp\Server;

use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Progress\ProgressReporterInterface;

final class WorkDoneProgressReporter implements ProgressReporterInterface
{
    private bool $supported = false;
    private int $nextToken = 1;

    public function __construct(private readonly ClientInterface $client)
    {
    }

    /** @param array<array-key, mixed> $initializeParams */
    public function initialize(array $initializeParams): void
    {
        $capabilities = $initializeParams['capabilities'] ?? null;
        $window = \is_array($capabilities) ? ($capabilities['window'] ?? null) : null;
        $this->supported = \is_array($window) && true === ($window['workDoneProgress'] ?? null);
    }

    public function begin(string $title, string $message): ?string
    {
        if (!$this->supported) {
            return null;
        }

        $token = 'symfony-lsp-'.($this->nextToken++);
        try {
            $this->client->request('window/workDoneProgress/create', ['token' => $token]);
            $this->client->notify('$/progress', [
                'token' => $token,
                'value' => [
                    'kind' => 'begin',
                    'title' => $title,
                    'message' => $message,
                    'cancellable' => false,
                ],
            ]);
        } catch (\Throwable) {
            return null;
        }

        return $token;
    }

    public function end(?string $token, string $message): void
    {
        if (null === $token) {
            return;
        }

        try {
            $this->client->notify('$/progress', [
                'token' => $token,
                'value' => [
                    'kind' => 'end',
                    'message' => $message,
                ],
            ]);
        } catch (\Throwable) {
        }
    }
}
