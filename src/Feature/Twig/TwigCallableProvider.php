<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Parser\Php\PhpMethodDeclaration;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigCallableProvider implements DefinitionProviderInterface, HoverProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly DocumentStore $documentStore,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigCallableIndexRegistry $indexes,
        private readonly TwigCallableReferenceExtractor $references,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly UriToPathConverter $uriToPathConverter,
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
        $value = \sprintf('Twig %s: `%s`', $reference->kind()->value, $reference->name());
        $callables = [];
        foreach ($declarations as $declaration) {
            if (null !== $declaration->className() && null !== $declaration->method()) {
                $callables[$declaration->className().'::'.$declaration->method()] = $declaration;
            }
        }
        ksort($callables);
        if (1 === \count($callables)) {
            $name = array_key_first($callables);
            $declaration = $callables[$name];
            $value .= "\n\nCallable: `".$name.'`';
            $method = $this->callableMethods($project, $declaration)[0]['method'] ?? null;
            if ($method instanceof PhpMethodDeclaration) {
                $value .= "\n\n```php\n".$method->signature()."\n```";
                if (null !== $method->description()) {
                    $value .= "\n\n".$method->description();
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
        $locations = [];
        foreach ($declarations as $declaration) {
            $methods = $this->callableMethods($project, $declaration);
            if ([] === $methods) {
                $locations[] = $this->protocol->location($declaration->uri(), $declaration->range());
                continue;
            }
            foreach ($methods as $method) {
                $locations[] = $this->protocol->location(
                    $method['uri'],
                    new Range(
                        $this->converter->toPosition($method['text'], $method['method']->nameStartOffset()),
                        $this->converter->toPosition($method['text'], $method['method']->nameEndOffset()),
                    ),
                );
            }
        }

        return $this->unique($locations);
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{TwigCallableReference, list<TwigCallableDeclaration>, Project}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->documents->resolvePositioned($params);
        if (null === $request || 'twig' !== $request->document->languageId()) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        $reference = $this->references->at($request->document->text(), $offset);
        if (null === $reference) {
            return null;
        }
        $declarations = $this->indexes->forProject($request->project)->declarations($reference->kind(), $reference->name());

        return [] === $declarations ? null : [$reference, $declarations, $request->project];
    }

    /** @return list<array{uri: string, text: string, method: PhpMethodDeclaration}> */
    private function callableMethods(Project $project, TwigCallableDeclaration $declaration): array
    {
        if (null === $declaration->className() || null === $declaration->method()) {
            return [];
        }
        $methods = [];
        foreach ($this->classIndexes->forProject($project)->classDeclarations($declaration->className()) as $class) {
            $text = $this->source($class->uri());
            if (null === $text) {
                continue;
            }
            foreach ($this->phpParser->parse($text)->methodDeclarations() as $method) {
                if (0 === strcasecmp($declaration->className(), $method->className()) && 0 === strcasecmp($declaration->method(), $method->name())) {
                    $methods[] = ['uri' => $class->uri(), 'text' => $text, 'method' => $method];
                }
            }
        }

        return $methods;
    }

    private function source(string $uri): ?string
    {
        if (null !== $document = $this->documentStore->get($uri)) {
            return $document->text();
        }
        $path = $this->uriToPathConverter->convert($uri);
        if (null === $path || !is_file($path)) {
            return null;
        }
        $text = file_get_contents($path);

        return false === $text ? null : $text;
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
