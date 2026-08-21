<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CodeLensProviderInterface;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class MessengerProvider implements CodeLensProviderInterface, CompletionProviderInterface, DefinitionProviderInterface, DiagnosticProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly MessengerIndexRegistry $indexes,
        private readonly MessengerSourceIndexRegistry $sourceIndexes,
        private readonly MessengerExtractor $extractor,
        private readonly PhpClassDeclarationExtractor $classExtractor,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        $before = substr($request->document->text(), 0, $offset);
        $kind = null;
        $prefix = '';
        $messengerOptionContext = 'yaml' === $request->document->languageId() || ('php' === $request->document->languageId() && preg_match('/AsMessageHandler\s*\([^)]*$/s', $before));
        if ($messengerOptionContext && preg_match('/(?:\bbus|default_bus)\s*:\s*["\']?([A-Za-z0-9_.-]*)$/', $before, $match)) {
            $kind = MessengerSymbolKind::Bus;
            $prefix = $match[1];
        } elseif ($messengerOptionContext && preg_match('/(?:fromTransport|from_transport|failure_transport)\s*:\s*["\']?([A-Za-z0-9_.-]*)$/', $before, $match)) {
            $kind = MessengerSymbolKind::Transport;
            $prefix = $match[1];
        } elseif (preg_match('/BusNameStamp\s*\(\s*["\']([A-Za-z0-9_.-]*)$/', $before, $match)) {
            $kind = MessengerSymbolKind::Bus;
            $prefix = $match[1];
        } elseif ('yaml' === $request->document->languageId() && \array_slice($this->yamlParentPath($before), -3) === ['framework', 'messenger', 'routing'] && preg_match('/:\s*\[?\s*["\']?([A-Za-z0-9_.-]*)$/', substr($before, (int) strrpos("\n".$before, "\n")), $match)) {
            $kind = MessengerSymbolKind::Transport;
            $prefix = $match[1];
        }
        if (null === $kind) {
            return null;
        }
        $items = [];
        $index = $this->indexes->forProject($request->project);
        $names = MessengerSymbolKind::Bus === $kind ? array_map(static fn (MessengerBus $bus): string => $bus->name(), $index->buses()) : array_map(static fn (MessengerTransport $transport): string => $transport->name(), $index->transports());
        foreach ($names as $name) {
            if (str_starts_with($name, $prefix)) {
                $items[] = $this->completion($name, $request->document->text(), $offset - \strlen($prefix), $request->position);
            }
        }

        return $items;
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$symbol, $class, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        $lines = [];
        if ($symbol instanceof MessengerSourceSymbol) {
            $message = MessengerSymbolKind::Message === $symbol->kind() ? $index->message($symbol->name()) : null;
            if (MessengerSymbolKind::Bus === $symbol->kind() && null !== $bus = $index->bus($symbol->name())) {
                $handlers = array_filter($index->messages(), static fn (MessengerMessage $message): bool => [] !== array_filter($index->handlersForMessage($message->className()), static fn (MessengerHandler $handler): bool => $handler->bus() === $bus->name()));
                $lines = ['Messenger bus: `'.$bus->name().'`', '', 'Default: '.($bus->isDefault() ? 'yes' : 'no'), '', 'Handled messages: '.\count($handlers)];
            } elseif (MessengerSymbolKind::Transport === $symbol->kind() && null !== $transport = $index->transport($symbol->name())) {
                $routed = array_filter($index->messages(), static fn (MessengerMessage $message): bool => \in_array($transport->name(), $message->transports(), true));
                $lines = ['Messenger transport: `'.$transport->name().'`', '', 'Failure transport: '.($transport->isFailure() ? 'yes' : 'no'), '', 'Routed messages: '.\count($routed)];
            } elseif (MessengerSymbolKind::Message === $symbol->kind() && (null !== $message || [] !== $this->handlersForMessage($project, $index, $symbol->name()))) {
                $handlers = $this->handlersForMessage($project, $index, $symbol->name());
                $transports = $message?->transports() ?? [];
                $lines = ['Messenger message: `'.$symbol->name().'`', '', 'Transports: '.([] === $transports ? 'none' : '`'.implode('`, `', $transports).'`'), '', 'Handlers: '.([] === $handlers ? 'none' : '`'.implode('`, `', array_map(static fn (MessengerHandler $handler): string => $handler->className().'::'.$handler->method(), $handlers)).'`')];
            }
        } elseif ($class instanceof PhpClassDeclaration) {
            $message = $index->message($class->className());
            $messageHandlers = $this->handlersForMessage($project, $index, $class->className());
            if (null !== $message || [] !== $messageHandlers) {
                $transports = $message?->transports() ?? [];
                $lines = ['Messenger message: `'.$class->className().'`', '', 'Transports: '.([] === $transports ? 'none' : '`'.implode('`, `', $transports).'`'), '', 'Handlers: '.([] === $messageHandlers ? 'none' : '`'.implode('`, `', array_map(static fn (MessengerHandler $handler): string => $handler->className().'::'.$handler->method(), $messageHandlers)).'`')];
            } else {
                $handled = $index->handlersByClass($class->className());
                if ([] !== $handled) {
                    $lines = ['Messenger handler: `'.$class->className().'`', '', 'Messages: `'.implode('`, `', array_values(array_unique(array_map(static fn (MessengerHandler $handler): string => $handler->message(), $handled)))).'`', '', 'Buses: `'.implode('`, `', array_values(array_unique(array_map(static fn (MessengerHandler $handler): string => $handler->bus(), $handled)))).'`'];
                }
            }
        }

        return [] === $lines ? null : $this->protocol->markdownHover(implode("\n", $lines));
    }

    public function definition(array $params): ?array
    {
        return $this->relations($params, true);
    }

    public function references(array $params): ?array
    {
        return $this->relations($params, false);
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        if (!$index->isComplete()) {
            return [];
        }
        $diagnostics = [];
        foreach ($this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text())->symbols() as $symbol) {
            if ($symbol->isDeclaration() || MessengerSymbolKind::Message === $symbol->kind()) {
                continue;
            }
            $known = MessengerSymbolKind::Bus === $symbol->kind() ? null !== $index->bus($symbol->name()) : null !== $index->transport($symbol->name());
            if (!$known) {
                $diagnostics[] = $this->protocol->diagnostic($symbol->range(), 1, MessengerSymbolKind::Bus === $symbol->kind() ? 'messenger.unknown_bus' : 'messenger.unknown_transport', \sprintf('Unknown Messenger %s "%s".', strtolower($symbol->kind()->name), $symbol->name()));
            }
        }
        if ('php' === $request->document->languageId()) {
            $scalarTypes = ['array', 'bool', 'callable', 'float', 'int', 'never', 'resource', 'string', 'void'];
            foreach ($this->classExtractor->extract($request->document->uri(), $request->document->text()) as $class) {
                foreach ($index->handlersByClass($class->className()) as $handler) {
                    $pattern = '/\bfunction\s+'.preg_quote($handler->method(), '/').'\s*\(\s*(?:[A-Za-z_][A-Za-z0-9_]*\s+)*(\\??[A-Za-z_][A-Za-z0-9_]*)\s+\$/';
                    if (preg_match($pattern, $request->document->text(), $match, \PREG_OFFSET_CAPTURE) && \in_array(strtolower(ltrim($match[1][0], '?')), $scalarTypes, true)) {
                        $start = $match[1][1];
                        $range = new Range($this->converter->toPosition($request->document->text(), $start), $this->converter->toPosition($request->document->text(), $start + \strlen($match[1][0])));
                        $diagnostics[] = $this->protocol->diagnostic($range, 1, 'messenger.invalid_handler_signature', \sprintf('Messenger handler "%s::%s" cannot accept message "%s".', $handler->className(), $handler->method(), $handler->message()));
                    }
                }
            }
        }

        return $diagnostics;
    }

    public function codeLenses(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'php' !== $request->document->languageId()) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $lenses = [];
        foreach ($this->classExtractor->extract($request->document->uri(), $request->document->text()) as $class) {
            $message = $index->message($class->className());
            $messageHandlers = $this->handlersForMessage($request->project, $index, $class->className());
            if (null !== $message || [] !== $messageHandlers) {
                $related = array_values(array_unique(array_map(static fn (MessengerHandler $handler): string => $handler->className(), $messageHandlers)));
                $count = \count($related);
                $lenses[] = $this->protocol->referenceLens($class->range(), \sprintf('%d Messenger handler%s', $count, 1 === $count ? '' : 's'), $class->uri(), $this->classLocations($request->project, $related));
            } elseif ([] !== $handlers = $index->handlersByClass($class->className())) {
                $related = array_values(array_unique(array_map(static fn (MessengerHandler $handler): string => $handler->message(), $handlers)));
                $count = \count($related);
                $lenses[] = $this->protocol->referenceLens($class->range(), \sprintf('Handles %d Messenger message%s', $count, 1 === $count ? '' : 's'), $class->uri(), $this->classLocations($request->project, $related));
            }
        }

        return $lenses;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{MessengerSourceSymbol|null, PhpClassDeclaration|null, Project}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        foreach ($this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text())->symbols() as $symbol) {
            if ($this->contains($request->document->text(), $symbol->range(), $offset)) {
                return [$symbol, null, $request->project];
            }
        }
        if ('php' === $request->document->languageId()) {
            foreach ($this->classExtractor->extract($request->document->uri(), $request->document->text()) as $class) {
                if ($this->contains($request->document->text(), $class->range(), $offset)) {
                    return [null, $class, $request->project];
                }
            }
        }

        return null;
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
                    array_push($locations, ...array_map(fn (MessengerSourceSymbol $item): array => $this->protocol->location($item->uri(), $item->range()), $symbols));
                }

                return $locations;
            }
            if ($definitionsOnly) {
                $symbols = array_values(array_filter($symbols, static fn (MessengerSourceSymbol $item): bool => $item->isDeclaration()));
            }

            return array_map(fn (MessengerSourceSymbol $item): array => $this->protocol->location($item->uri(), $item->range()), $symbols);
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
        $locations = [];
        foreach (array_keys($relatedClasses) as $className) {
            foreach ($this->classIndexes->forProject($project)->classDeclarations($className) as $declaration) {
                $locations[] = $this->protocol->location($declaration->uri(), $declaration->range());
            }
        }
        if (!$definitionsOnly && null !== $messageClass) {
            foreach ($this->sourceIndexes->forProject($project)->symbols(MessengerSymbolKind::Message, $messageClass) as $reference) {
                $locations[] = $this->protocol->location($reference->uri(), $reference->range());
            }
        }

        return $locations;
    }

    private function contains(string $text, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($text, $range->start()) && $offset <= $this->converter->toByteOffset($text, $range->end());
    }

    /** @return list<string> */
    private function yamlParentPath(string $before): array
    {
        $lines = preg_split('/\R/', $before);
        if (false === $lines) {
            return [];
        }
        array_pop($lines);
        $stack = [];
        foreach ($lines as $line) {
            if (!preg_match('/^(\s*)([^#:\s][^:#]*?)\s*:\s*(.*)$/', $line, $match)) {
                continue;
            }
            $indent = \strlen($match[1]);
            foreach (array_keys($stack) as $level) {
                if ($level >= $indent) {
                    unset($stack[$level]);
                }
            }
            $parent = [];
            ksort($stack);
            foreach ($stack as $path) {
                $parent = $path;
            }
            if ('' === trim($match[3])) {
                $stack[$indent] = [...$parent, trim($match[2], " \t\"'")];
            }
        }
        $parent = [];
        ksort($stack);
        foreach ($stack as $path) {
            $parent = $path;
        }

        return $parent;
    }

    /** @return array<array-key, mixed> */
    private function completion(string $name, string $text, int $start, Position $end): array
    {
        $position = $this->converter->toPosition($text, $start);

        return ['label' => $name, 'kind' => 12, 'textEdit' => $this->protocol->textEdit(new Range($position, $end), $name)];
    }

    /** @return list<MessengerHandler> */
    private function handlersForMessage(Project $project, MessengerIndex $index, string $className): array
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
    private function classLocations(Project $project, array $classNames): array
    {
        $locations = [];
        foreach ($classNames as $className) {
            foreach ($this->classIndexes->forProject($project)->classDeclarations($className) as $declaration) {
                $locations[] = $this->protocol->location($declaration->uri(), $declaration->range());
            }
        }

        return $locations;
    }
}
