<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderInterface;

final class ProjectMessengerSnapshotLoader implements RuntimeSnapshotLoaderInterface
{
    public function __construct(private readonly MessengerIndexRegistry $indexes)
    {
    }

    public function section(): string
    {
        return 'messenger';
    }

    public function load(Project $project, array $snapshot): void
    {
        $sections = $snapshot['sections'] ?? null;
        $section = \is_array($sections) ? ($sections['messenger'] ?? null) : null;
        if (!\is_array($section)) {
            return;
        }
        $buses = [];
        foreach (\is_array($section['buses'] ?? null) ? $section['buses'] : [] as $item) {
            if (\is_array($item) && \is_string($item['name'] ?? null)) {
                $buses[] = new MessengerBus($item['name'], true === ($item['default'] ?? false));
            }
        }
        $transports = [];
        foreach (\is_array($section['transports'] ?? null) ? $section['transports'] : [] as $item) {
            if (\is_array($item) && \is_string($item['name'] ?? null)) {
                $transports[] = new MessengerTransport($item['name'], true === ($item['failure'] ?? false));
            }
        }
        $messages = [];
        foreach (\is_array($section['messages'] ?? null) ? $section['messages'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['class'] ?? null)) {
                continue;
            }
            $messages[] = new MessengerMessage($item['class'], array_values(array_filter(\is_array($item['transports'] ?? null) ? $item['transports'] : [], 'is_string')));
        }
        $handlers = [];
        foreach (\is_array($section['handlers'] ?? null) ? $section['handlers'] : [] as $item) {
            if (!\is_array($item) || !\is_string($item['message'] ?? null) || !\is_string($item['bus'] ?? null) || !\is_string($item['service'] ?? null) || !\is_string($item['class'] ?? null) || !\is_string($item['method'] ?? null)) {
                continue;
            }
            $handlers[] = new MessengerHandler($item['message'], $item['bus'], $item['service'], $item['class'], $item['method'], \is_int($item['priority'] ?? null) ? $item['priority'] : 0, \is_string($item['fromTransport'] ?? null) ? $item['fromTransport'] : null);
        }
        $this->indexes->forProject($project)->replace($buses, $transports, $messages, $handlers, true === ($section['complete'] ?? false));
    }
}
