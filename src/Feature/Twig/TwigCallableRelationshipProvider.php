<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Index\SourceSymbolOrder;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\ReferencesRequest;

final class TwigCallableRelationshipProvider implements DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigCallableSourceIndexRegistry $indexes,
        private readonly TwigCallableReferenceExtractor $references,
        private readonly TwigCallableMethodResolver $methods,
        private readonly PhpParserInterface $phpParser,
    ) {
    }

    public function hover(PositionedRequest $request): ?array
    {
        $resolved = $this->resolve($request);
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

    public function definition(PositionedRequest $request): array
    {
        $resolved = $this->resolve($request);
        if (null === $resolved) {
            return [];
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

    public function references(ReferencesRequest $request): array
    {
        $resolved = $this->resolve($request);
        if (null !== $resolved) {
            [, $declarations, $project] = $resolved;

            return $this->referenceLocations($project, $declarations);
        }

        if ('php' !== $request->document->languageId) {
            return [];
        }
        $index = $this->indexes->forProject($request->project);
        $declaration = $index->declarationAt($request->document->uri, $request->position);
        if (null !== $declaration) {
            return $this->referenceLocations($request->project, [$declaration]);
        }
        if (!$index->hasCallableDeclarations()) {
            return [];
        }
        $offset = $request->offset;
        foreach ($this->phpParser->parse($request->document->text)->methodDeclarations as $method) {
            if ($offset < $method->nameStartOffset || $offset > $method->nameEndOffset) {
                continue;
            }
            $declarations = $index->declarationsForCallable($method->className, $method->name);

            return $this->referenceLocations($request->project, $declarations);
        }

        return [];
    }

    /** @return array{TwigCallableReference, list<TwigCallableDeclaration>, Project}|null */
    private function resolve(PositionedRequest $request): ?array
    {
        if ('twig' !== $request->document->languageId) {
            return null;
        }
        $offset = $request->offset;
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
            usort($usages, SourceSymbolOrder::byLocation(...));
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
