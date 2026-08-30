<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class MessengerCodeLensProvider implements CodeLensProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly LspProtocolMapper $protocol,
        private readonly MessengerIndexRegistry $indexes,
        private readonly PhpClassDeclarationExtractor $classExtractor,
        private readonly MessengerRelationshipResolver $relationships,
    ) {
    }

    public function codeLenses(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        if (null === $request || 'php' !== $request->document->languageId) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $lenses = [];
        foreach ($this->classExtractor->extract($request->document->uri, $request->document->text) as $class) {
            $message = $index->message($class->className());
            $messageHandlers = $this->relationships->handlersForMessage($request->project, $index, $class->className());
            if (null !== $message || [] !== $messageHandlers) {
                $related = [];
                foreach ($messageHandlers as $handler) {
                    $related[$handler->className()] = true;
                }
                $classNames = array_keys($related);
                $count = \count($classNames);
                $lenses[] = $this->protocol->referenceLens($class->range(), \sprintf('%d Messenger handler%s', $count, 1 === $count ? '' : 's'), $class->uri(), $this->relationships->classLocations($request->project, $classNames));
            } elseif ([] !== $handlers = $index->handlersByClass($class->className())) {
                $related = [];
                foreach ($handlers as $handler) {
                    $related[$handler->message()] = true;
                }
                $classNames = array_keys($related);
                $count = \count($classNames);
                $lenses[] = $this->protocol->referenceLens($class->range(), \sprintf('Handles %d Messenger message%s', $count, 1 === $count ? '' : 's'), $class->uri(), $this->relationships->classLocations($request->project, $classNames));
            }
        }

        return $lenses;
    }
}
