<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\ProjectDocumentReader;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Project\Project;

final class TwigCallableMethodResolver
{
    public function __construct(
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly ProjectDocumentReader $reader,
        private readonly PhpParserInterface $phpParser,
        private readonly TwigCallableSourceIndexRegistry $callableIndexes,
        private readonly TwigCallableRuntimeIndexRegistry $runtimeIndexes,
    ) {
    }

    /** Declarations found in the project sources win because they reflect unsaved edits. */
    public function callableParameters(Project $project, TwigCallableKind $kind, string $name): ?TwigCallableParameters
    {
        $declarations = $this->callableIndexes->forProject($project)->declarations($kind, $name);
        if ([] !== $declarations) {
            return $this->parameters($project, ['callable' => ['kind' => $kind, 'declarations' => $declarations]])['callable'] ?? null;
        }
        foreach ($this->runtimeIndexes->forProject($project)->declarations($kind, $name) as $runtime) {
            $method = $this->runtimeMethod($runtime);
            if (null !== $method) {
                return $this->methodParameters(
                    array_map(
                        static fn ($parameter): TwigCallableMethodParameter => new TwigCallableMethodParameter($parameter->name, $parameter->types, $parameter->variadic),
                        $method->declaration->parameters,
                    ),
                    $method->reliable,
                    $this->runtimeDeclaration($runtime),
                    $kind,
                );
            }
        }

        return null;
    }

    public function callableMethod(Project $project, TwigCallableKind $kind, string $name): ?TwigCallableResolvedMethod
    {
        $declarations = $this->callableIndexes->forProject($project)->declarations($kind, $name);
        if ([] !== $declarations) {
            foreach ($declarations as $declaration) {
                foreach ($this->resolve($project, [$declaration]) as $method) {
                    return $method;
                }
            }

            return null;
        }
        foreach ($this->runtimeIndexes->forProject($project)->declarations($kind, $name) as $runtime) {
            $method = $this->runtimeMethod($runtime);
            if (null !== $method) {
                return $method;
            }
        }

        return null;
    }

    private function runtimeMethod(TwigCallableRuntimeDeclaration $runtime): ?TwigCallableResolvedMethod
    {
        $source = $this->reader->readRuntimeFile($runtime->uri)?->text;
        if (null === $source) {
            return null;
        }
        $document = $this->phpParser->parse($source);
        foreach ($document->methodDeclarations as $method) {
            if (0 === strcasecmp($runtime->declaringClassName, $method->className) && 0 === strcasecmp($runtime->method, $method->name)) {
                return new TwigCallableResolvedMethod($runtime->uri, $source, $method, [] === $document->diagnostics);
            }
        }

        return null;
    }

    private function runtimeDeclaration(TwigCallableRuntimeDeclaration $runtime): TwigCallableDeclaration
    {
        return new TwigCallableDeclaration(
            kind: $runtime->kind,
            name: $runtime->name,
            uri: $runtime->uri,
            range: new Range(new Position(0, 0), new Position(0, 0)),
            className: $runtime->className,
            method: $runtime->method,
            needsEnvironment: $runtime->needsEnvironment,
            needsContext: $runtime->needsContext,
            variadic: $runtime->variadic,
            needsCharset: $runtime->needsCharset,
            needsIsSandboxed: $runtime->needsIsSandboxed,
        );
    }

    /**
     * @param list<TwigCallableDeclaration> $declarations
     *
     * @return list<TwigCallableResolvedMethod>
     */
    public function resolve(Project $project, array $declarations): array
    {
        $targets = [];
        foreach ($declarations as $declaration) {
            if (null === $declaration->className || null === $declaration->method) {
                continue;
            }
            $className = ltrim($declaration->className, '\\');
            $targets[TwigCallableKey::from($className, $declaration->method)] = [$className, $declaration->method];
        }

        /** @var array<string, string|null> $sources */
        $sources = [];
        /** @var array<string, PhpDocument> $documents */
        $documents = [];
        $resolved = [];
        foreach ($targets as $callableKey => [$className, $methodName]) {
            foreach ($this->classIndexes->forProject($project)->classDeclarations($className) as $class) {
                if (!\array_key_exists($class->uri, $sources)) {
                    $sources[$class->uri] = $this->reader->read($project, $class->uri)?->text;
                }
                $source = $sources[$class->uri];
                if (null === $source) {
                    continue;
                }
                $document = $documents[$class->uri] ??= $this->phpParser->parse($source);
                foreach ($document->methodDeclarations as $method) {
                    if (0 !== strcasecmp($className, $method->className) || 0 !== strcasecmp($methodName, $method->name)) {
                        continue;
                    }
                    $key = $callableKey."\0".$class->uri."\0".$method->nameStartOffset;
                    $resolved[$key] = new TwigCallableResolvedMethod($class->uri, $source, $method, [] === $document->diagnostics);
                }
            }
        }

        return array_values($resolved);
    }

    /**
     * @param array<string, array{kind: TwigCallableKind, declarations: list<TwigCallableDeclaration>}> $callables
     *
     * @return array<string, TwigCallableParameters>
     */
    public function parameters(Project $project, array $callables): array
    {
        $index = $this->callableIndexes->forProject($project);
        $fallbackDeclarations = [];
        foreach ($callables as $callable) {
            foreach ($callable['declarations'] as $declaration) {
                if (null !== $declaration->className
                    && null !== $declaration->method
                    && null === $index->method($declaration->className, $declaration->method)
                ) {
                    $fallbackDeclarations[] = $declaration;
                }
            }
        }
        $fallbackMethods = [];
        foreach ($this->resolve($project, $fallbackDeclarations) as $method) {
            $fallbackMethods[TwigCallableKey::from($method->declaration->className, $method->declaration->name)] ??= $method;
        }

        $parameters = [];
        foreach ($callables as $key => $callable) {
            foreach ($callable['declarations'] as $declaration) {
                if (null === $declaration->className || null === $declaration->method) {
                    continue;
                }
                $method = $index->method($declaration->className, $declaration->method);
                if (null !== $method) {
                    $methodParameters = $method->parameters;
                    $reliable = $method->reliable;
                } else {
                    $fallback = $fallbackMethods[TwigCallableKey::from($declaration->className, $declaration->method)] ?? null;
                    if (null === $fallback) {
                        continue;
                    }
                    $methodParameters = array_map(
                        static fn ($parameter): TwigCallableMethodParameter => new TwigCallableMethodParameter($parameter->name, $parameter->types, $parameter->variadic),
                        $fallback->declaration->parameters,
                    );
                    $reliable = $fallback->reliable;
                }
                $parameters[$key] = $this->methodParameters($methodParameters, $reliable, $declaration, $callable['kind']);
                break;
            }
        }

        return $parameters;
    }

    /** @param list<TwigCallableMethodParameter> $parameters */
    private function methodParameters(array $parameters, bool $reliable, TwigCallableDeclaration $callable, TwigCallableKind $kind): TwigCallableParameters
    {
        $all = array_map(static fn ($parameter): string => $parameter->name, $parameters);
        if ($callable->optionsKnown) {
            $skip = (int) $callable->needsCharset
                + (int) $callable->needsEnvironment
                + (int) $callable->needsContext
                + (int) $callable->needsIsSandboxed;
        } else {
            $skip = 'charset' === ($parameters[0]->name ?? null) ? 1 : 0;
            if (['Twig\\Environment'] === ($parameters[$skip]->types ?? [])) {
                ++$skip;
            }
            if ('context' === ($parameters[$skip]->name ?? null) && \in_array('array', $parameters[$skip]->types ?? [], true)) {
                ++$skip;
            }
            if ('isSandboxed' === ($parameters[$skip]->name ?? null)) {
                ++$skip;
            }
        }
        if (TwigCallableKind::Filter === $kind) {
            ++$skip;
        }
        $variadic = $callable->variadic || array_any($parameters, static fn ($parameter): bool => $parameter->variadic);
        $nameable = \array_slice($all, $skip);
        if ($variadic) {
            array_pop($nameable);
        }

        return new TwigCallableParameters($all, $nameable, $variadic, $callable->optionsKnown && $reliable);
    }
}
