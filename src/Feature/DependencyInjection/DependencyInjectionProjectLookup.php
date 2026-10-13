<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Index\SourceSymbols;
use Symfony\Lsp\Project\Project;

final class DependencyInjectionProjectLookup
{
    private const NAMED_ALIAS_PATTERN = '/^(\.)?([^\s$]+) \$(.+)$/';
    private const MAX_ALIAS_HOPS = 5;

    public function __construct(
        private readonly ServiceIndexRegistry $serviceIndexes,
        private readonly ParameterIndexRegistry $parameterIndexes,
        private readonly DependencyInjectionSourceIndexRegistry $sourceIndexes,
    ) {
    }

    /** @return list<Service> */
    public function matchingServices(Project $project, string $prefix): array
    {
        $services = [];
        foreach ($this->serviceIndexes->forProject($project)->matching($prefix) as $service) {
            $services[$service->id] = $service;
        }

        $sourceIndex = $this->sourceIndexes->forProject($project);
        foreach ($sourceIndex->serviceIds() as $id) {
            if (isset($services[$id]) || !str_starts_with($id, $prefix)) {
                continue;
            }

            $declaration = $sourceIndex->serviceDeclarations($id)[0];
            $services[$id] = $this->sourceService($declaration);
        }
        ksort($services);

        return array_values($services);
    }

    /** @return list<Parameter> */
    public function matchingParameters(Project $project, string $prefix): array
    {
        $parameters = [];
        foreach ($this->parameterIndexes->forProject($project)->matching($prefix) as $parameter) {
            $parameters[$parameter->name] = $parameter;
        }

        foreach ($this->sourceIndexes->forProject($project)->parameterNames() as $name) {
            if (!isset($parameters[$name]) && str_starts_with($name, $prefix)) {
                $parameters[$name] = new Parameter($name, null);
            }
        }
        ksort($parameters);

        return array_values($parameters);
    }

    /**
     * Lists the named autowiring aliases (`Type $name` and hidden `.Type $name`) usable with #[Target].
     *
     * @param list<string> $types an empty list accepts every type
     *
     * @return list<AutowiringTarget>
     */
    public function autowiringTargets(Project $project, array $types, string $prefix): array
    {
        $accepted = array_map(self::normalizeType(...), $types);
        $targets = [];
        foreach ($this->matchingServices($project, '') as $service) {
            if (!preg_match(self::NAMED_ALIAS_PATTERN, $service->id, $matches)) {
                continue;
            }

            [, $dot, $type, $name] = $matches;
            if ([] !== $accepted && !\in_array(self::normalizeType($type), $accepted, true)) {
                continue;
            }
            if (!str_starts_with($name, $prefix)) {
                continue;
            }

            $key = self::normalizeType($type).' $'.$name;
            if (isset($targets[$key])) {
                continue;
            }

            [$serviceId, $className] = $this->resolveAliasChain($project, $service);
            $targets[$key] = new AutowiringTarget($name, $type, $service->id, $serviceId, $className, '.' === $dot);
        }

        $targets = array_values($targets);
        usort($targets, static fn (AutowiringTarget $a, AutowiringTarget $b): int => [$a->name, $a->type] <=> [$b->name, $b->type]);

        return $targets;
    }

    /** @param list<string> $types an empty list accepts every type */
    public function autowiringTarget(Project $project, array $types, string $name): ?AutowiringTarget
    {
        if ('' === $name) {
            return null;
        }

        $targets = $this->autowiringTargets($project, $types, '');
        $camelName = self::camelCase($name);
        foreach ([[$name, false], [$name, true], [$camelName, false]] as [$candidate, $hidden]) {
            foreach ($targets as $target) {
                if ($candidate === $target->name && $hidden === $target->hidden) {
                    return $target;
                }
            }
        }

        return null;
    }

    public function service(Project $project, string $id): ?Service
    {
        $runtime = $this->serviceIndexes->forProject($project)->get($id);
        $source = $this->sourceIndexes->forProject($project)->serviceDeclarations($id)[0] ?? null;
        if (null === $runtime) {
            return null === $source ? null : $this->sourceService($source);
        }

        return new Service(
            $id,
            $runtime->className ?? $source?->className,
            $runtime->alias ?? $source?->alias,
            $runtime->public,
            $runtime->lazy,
            $runtime->deprecation,
            $runtime->tags,
            $runtime->decorates ?? $source?->decorates,
            $runtime->autowiringTypes,
            $runtime->decorationStack,
        );
    }

    public function parameter(Project $project, string $name): ?Parameter
    {
        $parameter = $this->parameterIndexes->forProject($project)->get($name);
        if (null !== $parameter) {
            return $parameter;
        }

        return [] === $this->sourceIndexes->forProject($project)->parameterDeclarations($name)
            ? null
            : new Parameter($name, null);
    }

    /** @return list<ServiceDeclaration|ParameterDeclaration|PhpClassDeclaration> */
    public function definitionTargets(Project $project, DependencyInjectionSymbol $symbol): array
    {
        $sourceIndex = $this->sourceIndexes->forProject($project);
        if (DependencyInjectionSymbolKind::Parameter === $symbol->kind) {
            return $sourceIndex->parameterDeclarations($symbol->name);
        }

        $targets = [];
        $serviceNames = [$symbol->name];
        $runtimeService = $this->serviceIndexes->forProject($project)->get($symbol->name);
        if (null !== $runtimeService?->alias) {
            $serviceNames[] = $runtimeService->alias;
        }
        foreach ($sourceIndex->serviceDeclarations($symbol->name) as $declaration) {
            $targets[] = $declaration;
            if (null !== $declaration->alias) {
                $serviceNames[] = $declaration->alias;
            }
        }
        foreach ($sourceIndex->decoratorsOf($symbol->name) as $declaration) {
            $targets[] = $declaration;
        }

        $classNames = [];
        foreach (array_values(array_unique($serviceNames)) as $serviceName) {
            $service = $this->serviceIndexes->forProject($project)->get($serviceName);
            if (null !== $service?->className) {
                $classNames[] = $service->className;
            }
            foreach ($sourceIndex->serviceDeclarations($serviceName) as $declaration) {
                if (null !== $declaration->className) {
                    $classNames[] = $declaration->className;
                }
            }
        }
        foreach (array_values(array_unique($classNames)) as $className) {
            foreach ($sourceIndex->classDeclarations($className) as $declaration) {
                $targets[] = $declaration;
            }
        }

        return SourceSymbols::unique($targets);
    }

    /** @return list<ServiceDeclaration|ParameterDeclaration> */
    public function declarations(Project $project, DependencyInjectionSymbolKind $kind, string $name): array
    {
        $index = $this->sourceIndexes->forProject($project);

        return DependencyInjectionSymbolKind::Service === $kind
            ? $index->serviceDeclarations($name)
            : $index->parameterDeclarations($name);
    }

    public function hasDeclaration(Project $project, DependencyInjectionSymbolKind $kind, string $name): bool
    {
        return [] !== $this->declarations($project, $kind, $name);
    }

    public function hasNameCollision(Project $project, DependencyInjectionSymbolKind $kind, string $name): bool
    {
        if (DependencyInjectionSymbolKind::Service === $kind) {
            return null !== $this->serviceIndexes->forProject($project)->get($name)
                || $this->hasDeclaration($project, $kind, $name);
        }

        return null !== $this->parameterIndexes->forProject($project)->get($name)
            || $this->hasDeclaration($project, $kind, $name);
    }

    /** @return array{?string, ?string} */
    private function resolveAliasChain(Project $project, Service $service): array
    {
        $current = $service;
        for ($hops = 0; null !== $current->alias && $hops < self::MAX_ALIAS_HOPS; ++$hops) {
            $next = $this->service($project, $current->alias);
            if (null === $next) {
                return [$current->alias, null];
            }
            $current = $next;
        }

        if (null !== $current->alias) {
            return [$current->alias, null];
        }

        return [$current->id, $current->className];
    }

    private static function normalizeType(string $type): string
    {
        return strtolower(ltrim($type, '\\'));
    }

    private static function camelCase(string $name): string
    {
        return lcfirst(str_replace(' ', '', ucwords((string) preg_replace('/[^a-zA-Z0-9\x7f-\xff]++/', ' ', $name))));
    }

    private function sourceService(ServiceDeclaration $declaration): Service
    {
        return new Service(
            $declaration->id,
            $declaration->className,
            $declaration->alias,
            null,
            null,
            null,
            $declaration->tags,
            $declaration->decorates,
            [],
        );
    }
}
