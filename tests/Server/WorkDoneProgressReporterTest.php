<?php

namespace Symfony\Lsp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Server\WorkDoneProgressReporter;

final class WorkDoneProgressReporterTest extends TestCase
{
    public function testCreatesAndCompletesSupportedProgress(): void
    {
        $client = new ProgressClient();
        $progress = new WorkDoneProgressReporter($client);
        $progress->initialize(['capabilities' => ['window' => ['workDoneProgress' => true]]]);

        $token = $progress->begin('Symfony source index', '/workspace');
        $progress->end($token, 'Source index ready');

        self::assertSame([['method' => 'window/workDoneProgress/create', 'params' => ['token' => 'symfony-lsp-1']]], $client->requests);
        self::assertSame([
            'begin',
            'end',
        ], array_map(static function (array $notification): mixed {
            $value = $notification['params']['value'] ?? null;

            return \is_array($value) ? ($value['kind'] ?? null) : null;
        }, $client->notifications));
    }
}

final class ProgressClient implements ClientInterface
{
    /** @var list<array{method: string, params: array<array-key, mixed>}> */
    public array $requests = [];

    /** @var list<array{method: string, params: array<array-key, mixed>}> */
    public array $notifications = [];

    public function request(string $method, array $params): mixed
    {
        $this->requests[] = ['method' => $method, 'params' => $params];

        return null;
    }

    public function notify(string $method, array $params): void
    {
        $this->notifications[] = ['method' => $method, 'params' => $params];
    }
}
