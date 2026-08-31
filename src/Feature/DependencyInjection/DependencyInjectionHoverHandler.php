<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class DependencyInjectionHoverHandler implements HoverProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly LspProtocolMapper $protocol,
        private readonly DependencyInjectionSymbolResolver $symbolResolver,
        private readonly DependencyInjectionProjectLookup $lookup,
    ) {
    }

    public function hover(array $params): ?array
    {
        $request = $this->documentContextResolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }

        $symbol = $this->symbolResolver->resolve(
            $request->document->uri,
            $request->document->languageId,
            $request->document->text,
            $request->position,
        );
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
}
