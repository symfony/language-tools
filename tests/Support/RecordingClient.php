<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Client\ClientInterface;

final class RecordingClient implements ClientInterface
{
    /** @var list<array{method: string, params: array<array-key, mixed>}> */
    public array $requests = [];

    /** @var list<array{method: string, params: array<array-key, mixed>}> */
    public array $notifications = [];

    public function __construct(private readonly mixed $response = null)
    {
    }

    public function request(string $method, array $params): mixed
    {
        $this->requests[] = ['method' => $method, 'params' => $params];

        return $this->response;
    }

    public function notify(string $method, array $params): void
    {
        $this->notifications[] = ['method' => $method, 'params' => $params];
    }
}
