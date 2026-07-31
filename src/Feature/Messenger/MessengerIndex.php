<?php

namespace Symfony\Lsp\Feature\Messenger;

final class MessengerIndex
{
    /** @var array<string, MessengerBus> */
    private array $buses = [];
    /** @var array<string, MessengerTransport> */
    private array $transports = [];
    /** @var array<string, MessengerMessage> */
    private array $messages = [];
    /** @var list<MessengerHandler> */
    private array $handlers = [];
    private bool $complete = false;

    /**
     * @param list<MessengerBus>       $buses
     * @param list<MessengerTransport> $transports
     * @param list<MessengerMessage>   $messages
     * @param list<MessengerHandler>   $handlers
     */
    public function replace(array $buses, array $transports, array $messages, array $handlers, bool $complete): void
    {
        $this->buses = [];
        foreach ($buses as $bus) {
            $this->buses[$bus->name()] = $bus;
        }
        ksort($this->buses);
        $this->transports = [];
        foreach ($transports as $transport) {
            $this->transports[$transport->name()] = $transport;
        }
        ksort($this->transports);
        $this->messages = [];
        foreach ($messages as $message) {
            $this->messages[$message->className()] = $message;
        }
        ksort($this->messages);
        $this->handlers = $handlers;
        $this->complete = $complete;
    }

    /** @return list<MessengerBus> */
    public function buses(): array
    {
        return array_values($this->buses);
    }

    public function bus(string $name): ?MessengerBus
    {
        return $this->buses[$name] ?? null;
    }

    /** @return list<MessengerTransport> */
    public function transports(): array
    {
        return array_values($this->transports);
    }

    public function transport(string $name): ?MessengerTransport
    {
        return $this->transports[$name] ?? null;
    }

    /** @return list<MessengerMessage> */
    public function messages(): array
    {
        return array_values($this->messages);
    }

    public function message(string $className): ?MessengerMessage
    {
        return $this->messages[ltrim($className, '\\')] ?? null;
    }

    /** @return list<MessengerHandler> */
    public function handlersForMessage(string $className): array
    {
        return array_values(array_filter($this->handlers, static fn (MessengerHandler $handler): bool => $handler->message() === ltrim($className, '\\')));
    }

    /** @return list<MessengerHandler> */
    public function handlersByClass(string $className): array
    {
        return array_values(array_filter($this->handlers, static fn (MessengerHandler $handler): bool => $handler->className() === ltrim($className, '\\')));
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
