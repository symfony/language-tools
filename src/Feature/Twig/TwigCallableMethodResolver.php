<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\ProjectDocumentReader;
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
    ) {
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
        $declarations = [];
        foreach ($callables as $callable) {
            array_push($declarations, ...$callable['declarations']);
        }
        $methods = [];
        foreach ($this->resolve($project, $declarations) as $method) {
            $methods[TwigCallableKey::from($method->declaration->className, $method->declaration->name)][] = $method;
        }

        $parameters = [];
        foreach ($callables as $key => $callable) {
            foreach ($callable['declarations'] as $declaration) {
                if (null === $declaration->className || null === $declaration->method) {
                    continue;
                }
                $method = $methods[TwigCallableKey::from($declaration->className, $declaration->method)][0] ?? null;
                if (null === $method) {
                    continue;
                }
                $parameters[$key] = $this->methodParameters($method, $declaration, $callable['kind']);
                break;
            }
        }

        return $parameters;
    }

    private function methodParameters(TwigCallableResolvedMethod $method, TwigCallableDeclaration $callable, TwigCallableKind $kind): TwigCallableParameters
    {
        $parameters = $method->declaration->parameters;
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

        return new TwigCallableParameters($all, $nameable, $variadic, $callable->optionsKnown && $method->reliable);
    }
}
