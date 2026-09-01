<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigCallableRelationshipProvider implements DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigCallableIndexRegistry $indexes,
        private readonly TwigCallableReferenceExtractor $references,
        private readonly TwigCallableMethodResolver $methods,
        private readonly PhpParserInterface $phpParser,
    ) {
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [$reference, $declarations, $project] = $resolved;
        $value = \sprintf('Twig %s: `%s`', $reference->kind->value, $reference->name);
        $callables = [];
        foreach ($declarations as $declaration) {
            if (null !== $declaration->className && null !== $declaration->method) {
                $callables[$declaration->className.'::'.$declaration->method] = $declaration;
            }
        }
        ksort($callables);
        if (1 === \count($callables)) {
            $name = array_key_first($callables);
            $value .= "\n\nCallable: `".$name.'`';
            $method = $this->methods->resolve($project, [array_values($callables)[0]])[0] ?? null;
            if (null !== $method) {
                $value .= "\n\n```php\n".$method->declaration->signature."\n```";
                if (null !== $method->declaration->description) {
                    $value .= "\n\n".$method->declaration->description;
                }
            }
        } elseif ([] !== $callables) {
            $value .= "\n\nCallables: `".implode('`, `', array_keys($callables)).'`';
        }

        return $this->protocol->markdownHover($value);
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }
        [, $declarations, $project] = $resolved;
        $methods = [];
        foreach ($this->methods->resolve($project, $declarations) as $method) {
            $methods[TwigCallableKey::from($method->declaration->className, $method->declaration->name)][] = $method;
        }
        $locations = [];
        foreach ($declarations as $declaration) {
            $callableMethods = null === $declaration->className || null === $declaration->method
                ? []
                : $methods[TwigCallableKey::from($declaration->className, $declaration->method)] ?? [];
            if ([] === $callableMethods) {
                $locations[] = $this->protocol->location($declaration->uri, $declaration->range);
                continue;
            }
            foreach ($callableMethods as $method) {
                $locations[] = $this->protocol->location(
                    $method->uri,
                    new Range(
                        $this->converter->toPosition($method->source, $method->declaration->nameStartOffset),
                        $this->converter->toPosition($method->source, $method->declaration->nameEndOffset),
                    ),
                );
            }
        }

        return $this->unique($locations);
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null !== $resolved) {
            [, $declarations, $project] = $resolved;

            return $this->referenceLocations($project, $declarations);
        }

        $request = $this->documents->resolvePositioned($params);
        if (null === $request || 'php' !== $request->document->languageId) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $declaration = $index->declarationAt($request->document->uri, $request->position);
        if (null !== $declaration) {
            return $this->referenceLocations($request->project, [$declaration]);
        }
        if (!$index->hasCallableDeclarations()) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        foreach ($this->phpParser->parse($request->document->text)->methodDeclarations as $method) {
            if ($offset < $method->nameStartOffset || $offset > $method->nameEndOffset) {
                continue;
            }
            $declarations = $index->declarationsForCallable($method->className, $method->name);

            return [] === $declarations ? null : $this->referenceLocations($request->project, $declarations);
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{TwigCallableReference, list<TwigCallableDeclaration>, Project}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->documents->resolvePositioned($params);
        if (null === $request || 'twig' !== $request->document->languageId) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        $reference = $this->references->at($request->document->text, $offset);
        if (null === $reference) {
            return null;
        }
        $declarations = $this->indexes->forProject($request->project)->declarations($reference->kind, $reference->name);

        return [] === $declarations ? null : [$reference, $declarations, $request->project];
    }

    /**
     * @param list<TwigCallableDeclaration> $declarations
     *
     * @return list<array<string, mixed>>
     */
    private function referenceLocations(Project $project, array $declarations): array
    {
        $pairs = [];
        foreach ($declarations as $declaration) {
            $pairs[$declaration->kind->value."\0".$declaration->name] = [$declaration->kind, $declaration->name];
        }
        $usages = [];
        $index = $this->indexes->forProject($project);
        foreach ($pairs as [$kind, $name]) {
            array_push($usages, ...$index->usages($kind, $name));
        }
        if (1 < \count($pairs)) {
            usort($usages, static fn (TwigCallableUsage $left, TwigCallableUsage $right): int => [$left->uri, $left->range->start->line, $left->range->start->character] <=> [$right->uri, $right->range->start->line, $right->range->start->character]);
        }

        return array_map(
            fn (TwigCallableUsage $usage): array => $this->protocol->location($usage->uri, $usage->range),
            $usages,
        );
    }

    /**
     * @param list<array<array-key, mixed>> $locations
     *
     * @return list<array<array-key, mixed>>
     */
    private function unique(array $locations): array
    {
        $unique = [];
        foreach ($locations as $location) {
            $unique[json_encode($location, \JSON_THROW_ON_ERROR)] = $location;
        }

        return array_values($unique);
    }
}
