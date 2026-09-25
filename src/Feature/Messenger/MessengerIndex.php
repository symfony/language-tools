<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Index\ClassNameKey;

final class MessengerIndex
{
    /** @var array<string, MessengerBus> */
    private array $buses = [];
    /** @var array<string, MessengerTransport> */
    private array $transports = [];
    /** @var array<string, MessengerMessage> */
    private array $messages = [];
    /** @var array<string, list<MessengerHandlerDeclaration>> */
    private array $handlersByMessage = [];
    /** @var array<string, list<MessengerHandlerDeclaration>> */
    private array $handlersByClass = [];
    private bool $complete = false;

    /**
     * @param list<MessengerBus>                $buses
     * @param list<MessengerTransport>          $transports
     * @param list<MessengerMessage>            $messages
     * @param list<MessengerHandlerDeclaration> $handlers
     */
    public function replace(array $buses, array $transports, array $messages, array $handlers, bool $complete): void
    {
        $this->buses = [];
        foreach ($buses as $bus) {
            $this->buses[$bus->name] = $bus;
        }
        ksort($this->buses);
        $this->transports = [];
        foreach ($transports as $transport) {
            $this->transports[$transport->name] = $transport;
        }
        ksort($this->transports);
        $this->messages = [];
        foreach ($messages as $message) {
            $this->messages[ClassNameKey::from($message->className)] = $message;
        }
        uasort($this->messages, static fn (MessengerMessage $left, MessengerMessage $right): int => $left->className <=> $right->className);
        $this->handlersByMessage = [];
        $this->handlersByClass = [];
        foreach ($handlers as $handler) {
            $this->handlersByMessage[ClassNameKey::from($handler->message)][] = $handler;
            $this->handlersByClass[ClassNameKey::from($handler->className)][] = $handler;
        }
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
        return $this->messages[ClassNameKey::from($className)] ?? null;
    }

    /** @return list<MessengerHandlerDeclaration> */
    public function handlersForMessage(string $className): array
    {
        return $this->handlersByMessage[ClassNameKey::from($className)] ?? [];
    }

    /** @return list<MessengerHandlerDeclaration> */
    public function handlersByClass(string $className): array
    {
        return $this->handlersByClass[ClassNameKey::from($className)] ?? [];
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
