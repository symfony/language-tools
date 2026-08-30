<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class MessengerRelationshipResolver
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly MessengerIndexRegistry $indexes,
        private readonly MessengerSourceIndexRegistry $sourceIndexes,
        private readonly MessengerExtractor $extractor,
        private readonly PhpClassDeclarationExtractor $classExtractor,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{MessengerSourceSymbol|null, PhpClassDeclaration|null, Project}|null
     */
    public function resolve(array $params): ?array
    {
        $request = $this->documents->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        foreach ($this->extractor->extract($request->document->uri, $request->document->languageId, $request->document->text)->symbols() as $symbol) {
            if ($this->converter->containsByteOffset($request->document->text, $symbol->range(), $offset, inclusiveEnd: true)) {
                return [$symbol, null, $request->project];
            }
        }
        if ('php' === $request->document->languageId) {
            foreach ($this->classExtractor->extract($request->document->uri, $request->document->text) as $class) {
                if ($this->converter->containsByteOffset($request->document->text, $class->range(), $offset, inclusiveEnd: true)) {
                    return [null, $class, $request->project];
                }
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function definitions(array $params): ?array
    {
        return $this->relations($params, true);
    }

    /** @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function references(array $params): ?array
    {
        return $this->relations($params, false);
    }

    /** @return list<MessengerHandler> */
    public function handlersForMessage(Project $project, MessengerIndex $index, string $className): array
    {
        $handlers = [];
        foreach ([$className, ...$this->sourceIndexes->forProject($project)->ancestors($className)] as $message) {
            foreach ($index->handlersForMessage($message) as $handler) {
                $key = implode('|', [$handler->message(), $handler->bus(), $handler->service(), $handler->method(), $handler->fromTransport() ?? '']);
                $handlers[$key] = $handler;
            }
        }

        return array_values($handlers);
    }

    /**
     * @param list<string> $classNames
     *
     * @return list<array<array-key, mixed>>
     */
    public function classLocations(Project $project, array $classNames): array
    {
        $locations = [];
        foreach ($classNames as $className) {
            foreach ($this->classIndexes->forProject($project)->classDeclarations($className) as $declaration) {
                $locations[] = $this->protocol->location($declaration->uri(), $declaration->range());
            }
        }

        return $locations;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    private function relations(array $params, bool $definitionsOnly): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $class, $project] = $resolved;
        if ($symbol instanceof MessengerSourceSymbol) {
            $symbols = $this->sourceIndexes->forProject($project)->symbols($symbol->kind(), $symbol->name());
            if (MessengerSymbolKind::Message === $symbol->kind()) {
                $classNames = [$symbol->name()];
                foreach ($this->handlersForMessage($project, $this->indexes->forProject($project), $symbol->name()) as $handler) {
                    $classNames[] = $handler->className();
                }
                $locations = $this->classLocations($project, array_values(array_unique($classNames)));
                if (!$definitionsOnly) {
                    foreach ($symbols as $item) {
                        $locations[] = $this->protocol->location($item->uri(), $item->range());
                    }
                }

                return $locations;
            }
            $locations = [];
            foreach ($symbols as $item) {
                if (!$definitionsOnly || $item->isDeclaration()) {
                    $locations[] = $this->protocol->location($item->uri(), $item->range());
                }
            }

            return $locations;
        }
        if (!$class instanceof PhpClassDeclaration) {
            return null;
        }
        $index = $this->indexes->forProject($project);
        $relatedClasses = [];
        $messageClass = null;
        $messageHandlers = $this->handlersForMessage($project, $index, $class->className());
        if (null !== $index->message($class->className()) || [] !== $messageHandlers) {
            $messageClass = $class->className();
            foreach ($messageHandlers as $handler) {
                $relatedClasses[$handler->className()] = true;
            }
        } else {
            foreach ($index->handlersByClass($class->className()) as $handler) {
                $relatedClasses[$handler->message()] = true;
            }
        }
        $locations = $this->classLocations($project, array_keys($relatedClasses));
        if (!$definitionsOnly && null !== $messageClass) {
            foreach ($this->sourceIndexes->forProject($project)->symbols(MessengerSymbolKind::Message, $messageClass) as $reference) {
                $locations[] = $this->protocol->location($reference->uri(), $reference->range());
            }
        }

        return $locations;
    }
}
