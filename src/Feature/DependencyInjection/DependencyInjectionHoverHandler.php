<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;

final class DependencyInjectionHoverHandler implements HoverProviderInterface
{
    public function __construct(
        private readonly LspProtocolMapper $protocol,
        private readonly DependencyInjectionSymbolResolver $symbolResolver,
        private readonly DependencyInjectionProjectLookup $lookup,
        private readonly PhpTargetAttributeResolver $targetArguments,
    ) {
    }

    public function hover(PositionedRequest $request): ?array
    {
        if ('php' === $request->document->languageId) {
            $argument = $this->targetArguments->resolveLiteral($request->document->text, $request->offset);
            if (null !== $argument) {
                return $this->targetHover($request, $argument);
            }
        }

        $symbol = $this->symbolResolver->resolve($request);
        if (null === $symbol) {
            return null;
        }

        if (DependencyInjectionSymbolKind::Parameter === $symbol->kind) {
            $parameter = $this->lookup->parameter($request->project, $symbol->name);
            if (null === $parameter) {
                return null;
            }

            $details = [\sprintf('Parameter: `%s`', $symbol->name)];
            if (null !== $parameter->deprecation) {
                $details[] = 'Deprecated: '.$parameter->deprecation;
            }

            return $this->protocol->markdownHover(implode("\n\n", $details));
        }

        $service = $this->lookup->service($request->project, $symbol->name);
        if (null === $service) {
            return null;
        }

        $details = [\sprintf('Service: `%s`', $symbol->name)];
        $alias = $service->alias;
        $className = $service->className;
        $decorates = $service->decorates;
        $tags = $service->tags;
        if (null !== $alias) {
            $details[] = \sprintf('Alias of: `%s`', $alias);
        }
        if (null !== $className) {
            $details[] = \sprintf('Class: `%s`', $className);
        }
        if (null !== $service->public) {
            $details[] = 'Visibility: '.($service->public ? 'public' : 'private');
        }
        if (true === $service->lazy) {
            $details[] = 'Lazy: yes';
        }
        if (null !== $service->deprecation) {
            $details[] = 'Deprecated: '.$service->deprecation;
        }
        if (null !== $decorates) {
            $details[] = \sprintf('Decorates: `%s`', $decorates);
        }
        if ([] !== $tags) {
            $details[] = \sprintf('Tags: `%s`', implode('`, `', $tags));
        }
        $autowiringTypes = $service->autowiringTypes;
        if ([] !== $autowiringTypes) {
            $details[] = \sprintf('Autowiring types: `%s`', implode('`, `', $autowiringTypes));
        }
        $decorationStack = $service->decorationStack;
        if ([] !== $decorationStack) {
            $details[] = \sprintf('Decoration stack: `%s`', implode('` → `', $decorationStack));
        }

        return $this->protocol->markdownHover(implode("\n\n", $details));
    }

    /** @return array<array-key, mixed>|null */
    private function targetHover(PositionedRequest $request, PhpTargetArgument $argument): ?array
    {
        if (null === $argument->name) {
            return null;
        }

        $target = $this->lookup->autowiringTarget($request->project, $argument->types, $argument->name);
        if (null === $target) {
            return null;
        }

        $details = [
            \sprintf('Autowiring target: `%s`', $argument->name),
            \sprintf('Type: `%s`', $target->type),
            \sprintf('Alias: `%s`', $target->aliasId),
        ];
        if (null !== $target->serviceId && $target->serviceId !== $target->aliasId) {
            $details[] = \sprintf('Service: `%s`', $target->serviceId);
        }
        if (null !== $target->className) {
            $details[] = \sprintf('Class: `%s`', $target->className);
        }

        return $this->protocol->markdownHover(implode("\n\n", $details));
    }
}
