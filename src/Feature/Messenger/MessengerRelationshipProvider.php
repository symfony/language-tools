<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclaration;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\LspRequestFactory;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\ReferencesRequest;

final class MessengerRelationshipProvider implements DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly LspRequestFactory $requests,
        private readonly LspProtocolMapper $protocol,
        private readonly MessengerIndexRegistry $indexes,
        private readonly MessengerRelationshipResolver $relationships,
    ) {
    }

    public function hover(array $params): ?array
    {
        $request = $this->requests->positioned($params);
        $resolved = null === $request ? null : $this->relationships->resolve($request);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $class, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        $lines = [];
        if ($symbol instanceof MessengerSourceSymbol) {
            $message = MessengerSymbolKind::Message === $symbol->kind ? $index->message($symbol->name) : null;
            if (MessengerSymbolKind::Bus === $symbol->kind && null !== $bus = $index->bus($symbol->name)) {
                $handled = 0;
                foreach ($index->messages() as $knownMessage) {
                    foreach ($index->handlersForMessage($knownMessage->className) as $handler) {
                        if ($handler->bus === $bus->name) {
                            ++$handled;
                            break;
                        }
                    }
                }
                $lines = ['Messenger bus: `'.$bus->name.'`', '', 'Default: '.($bus->default ? 'yes' : 'no'), '', 'Handled messages: '.$handled];
            } elseif (MessengerSymbolKind::Transport === $symbol->kind && null !== $transport = $index->transport($symbol->name)) {
                $routed = 0;
                foreach ($index->messages() as $knownMessage) {
                    if (\in_array($transport->name, $knownMessage->transports, true)) {
                        ++$routed;
                    }
                }
                $lines = ['Messenger transport: `'.$transport->name.'`', '', 'Failure transport: '.($transport->failure ? 'yes' : 'no'), '', 'Routed messages: '.$routed];
            } elseif (MessengerSymbolKind::Message === $symbol->kind) {
                $handlers = $this->relationships->handlersForMessage($project, $index, $symbol->name);
                if (null !== $message || [] !== $handlers) {
                    $lines = $this->messageLines($symbol->name, $message->transports ?? [], $handlers);
                }
            }
        } elseif ($class instanceof PhpClassDeclaration) {
            $message = $index->message($class->className);
            $messageHandlers = $this->relationships->handlersForMessage($project, $index, $class->className);
            if (null !== $message || [] !== $messageHandlers) {
                $lines = $this->messageLines($class->className, $message->transports ?? [], $messageHandlers);
            } else {
                $handled = $index->handlersByClass($class->className);
                if ([] !== $handled) {
                    $messages = [];
                    $buses = [];
                    foreach ($handled as $handler) {
                        $messages[$handler->message] = true;
                        $buses[$handler->bus] = true;
                    }
                    $lines = ['Messenger handler: `'.$class->className.'`', '', 'Messages: `'.implode('`, `', array_keys($messages)).'`', '', 'Buses: `'.implode('`, `', array_keys($buses)).'`'];
                }
            }
        }

        return [] === $lines ? null : $this->protocol->markdownHover(implode("\n", $lines));
    }

    public function definition(PositionedRequest $request): array
    {
        return $this->relationships->definitions($request);
    }

    public function references(ReferencesRequest $request): array
    {
        return $this->relationships->references($request);
    }

    /**
     * @param list<string>                      $transports
     * @param list<MessengerHandlerDeclaration> $handlers
     *
     * @return list<string>
     */
    private function messageLines(string $className, array $transports, array $handlers): array
    {
        $handlerNames = [];
        foreach ($handlers as $handler) {
            $handlerNames[] = $handler->className.'::'.$handler->method;
        }

        return ['Messenger message: `'.$className.'`', '', 'Transports: '.([] === $transports ? 'none' : '`'.implode('`, `', $transports).'`'), '', 'Handlers: '.([] === $handlerNames ? 'none' : '`'.implode('`, `', $handlerNames).'`')];
    }
}
