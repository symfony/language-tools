<?php

namespace Symfony\Lsp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Server\WorkDoneProgressReporter;
use Symfony\Lsp\Tests\Support\RecordingClient;

final class WorkDoneProgressReporterTest extends TestCase
{
    public function testCreatesAndCompletesSupportedProgress(): void
    {
        $client = new RecordingClient();
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
