<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\HoverProviderInterface;

final class DependencyInjectionHoverHandler implements HoverProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly DependencyInjectionSymbolResolver $symbolResolver,
        private readonly ServiceIndexRegistry $serviceIndexes,
        private readonly ParameterIndexRegistry $parameterIndexes,
        private readonly DependencyInjectionSourceIndexRegistry $sourceIndexes,
    ) {
    }

    public function hover(array $params): ?array
    {
        $request = $this->documentContextResolver->resolve($params);
        if (null === $request) {
            return null;
        }

        [$document, $project, $position] = $request;
        $symbol = $this->symbolResolver->resolve(
            $document->uri(),
            $document->languageId(),
            $document->text(),
            $position,
        );
        if (null === $symbol) {
            return null;
        }

        if (DependencyInjectionSymbolKind::Parameter === $symbol->kind()) {
            $parameter = $this->parameterIndexes->forProject($project)->get($symbol->name());
            $declarations = $this->sourceIndexes->forProject($project)->parameterDeclarations($symbol->name());
            if (null === $parameter && [] === $declarations) {
                return null;
            }

            $details = [\sprintf('Parameter: `%s`', $symbol->name())];
            if (null !== $parameter?->deprecation()) {
                $details[] = 'Deprecated: '.$parameter->deprecation();
            }

            return $this->result($details);
        }

        $service = $this->serviceIndexes->forProject($project)->get($symbol->name());
        $declaration = $this->sourceIndexes->forProject($project)->serviceDeclarations($symbol->name())[0] ?? null;
        if (null === $service && null === $declaration) {
            return null;
        }

        $details = [\sprintf('Service: `%s`', $symbol->name())];
        $alias = $service?->alias() ?? $declaration?->alias();
        $className = $service?->className() ?? $declaration?->className();
        $decorates = $service?->decorates() ?? $declaration?->decorates();
        $tags = $service?->tags() ?? $declaration?->tags() ?? [];
        if (null !== $alias) {
            $details[] = \sprintf('Alias of: `%s`', $alias);
        }
        if (null !== $className) {
            $details[] = \sprintf('Class: `%s`', $className);
        }
        if (null !== $service?->isPublic()) {
            $details[] = 'Visibility: '.($service->isPublic() ? 'public' : 'private');
        }
        if (true === $service?->isLazy()) {
            $details[] = 'Lazy: yes';
        }
        if (null !== $service?->deprecation()) {
            $details[] = 'Deprecated: '.$service->deprecation();
        }
        if (null !== $decorates) {
            $details[] = \sprintf('Decorates: `%s`', $decorates);
        }
        if ([] !== $tags) {
            $details[] = \sprintf('Tags: `%s`', implode('`, `', $tags));
        }
        $autowiringTypes = $service?->autowiringTypes() ?? [];
        if ([] !== $autowiringTypes) {
            $details[] = \sprintf('Autowiring types: `%s`', implode('`, `', $autowiringTypes));
        }
        $decorationStack = $service?->decorationStack() ?? [];
        if ([] !== $decorationStack) {
            $details[] = \sprintf('Decoration stack: `%s`', implode('` → `', $decorationStack));
        }

        return $this->result($details);
    }

    /**
     * @param list<string> $details
     *
     * @return array{contents: array{kind: string, value: string}}
     */
    private function result(array $details): array
    {
        return ['contents' => ['kind' => 'markdown', 'value' => implode("\n\n", $details)]];
    }
}
