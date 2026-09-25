<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;
use Symfony\Lsp\Runtime\SnapshotSection;

final class ProjectMessengerSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly MessengerIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'messenger';
    }

    public function load(Project $project, SnapshotSection $section): void
    {
        $buses = [];
        foreach ($section->items('buses', 'name') as $item) {
            $buses[] = new MessengerBus($item->string('name'), $item->bool('default'));
        }
        $transports = [];
        foreach ($section->items('transports', 'name') as $item) {
            $transports[] = new MessengerTransport($item->string('name'), $item->bool('failure'));
        }
        $messages = [];
        foreach ($section->items('messages', 'class') as $item) {
            $messages[] = new MessengerMessage($item->string('class'), $item->strings('transports'));
        }
        $handlers = [];
        foreach ($section->items('handlers', 'message', 'bus', 'service', 'class', 'method') as $item) {
            $handlers[] = new MessengerHandlerDeclaration(
                $item->string('message'),
                $item->string('bus'),
                $item->string('service'),
                $item->string('class'),
                $item->string('method'),
                $item->int('priority'),
                $item->optionalString('fromTransport'),
            );
        }
        $this->indexes->forProject($project)->replace($buses, $transports, $messages, $handlers, $section->complete());
    }
}
