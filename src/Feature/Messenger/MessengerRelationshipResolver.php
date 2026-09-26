<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassLocationResolver;
use Symfony\Lsp\Index\PositionedSourceSymbolResolver;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\ReferencesRequest;

final class MessengerRelationshipResolver
{
    public function __construct(
        private readonly PositionedSourceSymbolResolver $positionedSymbols,
        private readonly LspProtocolMapper $protocol,
        private readonly MessengerIndexRegistry $indexes,
        private readonly MessengerSourceIndexRegistry $sourceIndexes,
        private readonly MessengerExtractor $extractor,
        private readonly PhpClassDeclarationExtractor $classExtractor,
        private readonly PhpClassLocationResolver $classLocations,
    ) {
    }

    /** @return array{MessengerSourceSymbol|null, PhpClassDeclaration|null, Project}|null */
    public function resolve(PositionedRequest $request): ?array
    {
        $symbol = $this->positionedSymbols->resolve($request->source, $request->position, $this->extractor->extract($request->source)->symbols);
        if ($symbol instanceof MessengerSourceSymbol) {
            return [$symbol, null, $request->project];
        }
        $class = 'php' === $request->document->languageId
            ? $this->positionedSymbols->resolve($request->source, $request->position, $this->classExtractor->extract($request->document->uri, $request->document->text))
            : null;

        return $class instanceof PhpClassDeclaration ? [null, $class, $request->project] : null;
    }

    /** @return list<array<array-key, mixed>> */
    public function definitions(PositionedRequest $request): array
    {
        return $this->relations($request, null);
    }

    /** @return list<array<array-key, mixed>> */
    public function references(ReferencesRequest $request): array
    {
        return $this->relations($request, $request);
    }

    /** @return list<MessengerHandlerDeclaration> */
    public function handlersForMessage(Project $project, MessengerIndex $index, string $className): array
    {
        $handlers = [];
        foreach ([$className, ...$this->sourceIndexes->forProject($project)->ancestors($className)] as $message) {
            foreach ($index->handlersForMessage($message) as $handler) {
                $key = implode('|', [$handler->message, $handler->bus, $handler->service, $handler->method, $handler->fromTransport ?? '']);
                $handlers[$key] = $handler;
            }
        }

        return array_values($handlers);
    }

    /**
     * @param ReferencesRequest|null $references the request when it asks for references, null when it asks for definitions
     *
     * @return list<array<array-key, mixed>>
     */
    private function relations(PositionedRequest $request, ?ReferencesRequest $references): array
    {
        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return [];
        }
        [$symbol, $class, $project] = $resolved;
        if ($symbol instanceof MessengerSourceSymbol) {
            $symbols = $this->sourceIndexes->forProject($project)->symbols($symbol->kind, $symbol->name);
            if (MessengerSymbolKind::Message === $symbol->kind) {
                $classNames = [$symbol->name];
                foreach ($this->handlersForMessage($project, $this->indexes->forProject($project), $symbol->name) as $handler) {
                    $classNames[] = $handler->className;
                }
                $locations = $this->classLocations->locations($project, $classNames);
                if (null !== $references) {
                    array_push($locations, ...$this->protocol->locations($references->reported($symbols)));
                }

                return $locations;
            }

            return $this->protocol->locations(null === $references
                ? array_filter($symbols, static fn (MessengerSourceSymbol $item): bool => $item->declaration)
                : $references->reported($symbols));
        }
        if (!$class instanceof PhpClassDeclaration) {
            return [];
        }
        $index = $this->indexes->forProject($project);
        $relatedClasses = [];
        $messageClass = null;
        $messageHandlers = $this->handlersForMessage($project, $index, $class->className);
        if (null !== $index->message($class->className) || [] !== $messageHandlers) {
            $messageClass = $class->className;
            foreach ($messageHandlers as $handler) {
                $relatedClasses[$handler->className] = true;
            }
        } else {
            foreach ($index->handlersByClass($class->className) as $handler) {
                $relatedClasses[$handler->message] = true;
            }
        }
        $locations = $this->classLocations->locations($project, array_keys($relatedClasses));
        if (null !== $references && null !== $messageClass) {
            array_push($locations, ...$this->protocol->locations($references->reported(
                $this->sourceIndexes->forProject($project)->symbols(MessengerSymbolKind::Message, $messageClass),
            )));
        }

        return $locations;
    }
}
