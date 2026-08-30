<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\ProjectDocumentReader;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Parser\Php\PhpMethodDeclaration;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigCallableProvider implements CompletionProviderInterface, DefinitionProviderInterface, DiagnosticProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigCallableIndexRegistry $indexes,
        private readonly TwigCallableReferenceExtractor $references,
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly ProjectDocumentReader $reader,
        private readonly PhpParserInterface $phpParser,
        private readonly TwigCommentParser $comments,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->documents->resolvePositioned($params);
        if (null === $request || 'twig' !== $request->document->languageId) {
            return null;
        }
        $text = $request->document->text;
        $offset = $this->converter->toByteOffset($text, $request->position);
        $masked = $this->comments->mask($text);
        $before = substr($masked, 0, $offset);
        if (!$this->references->insideDirective($masked, $offset)) {
            return null;
        }
        $argumentItems = $this->completeArgumentNames($request->project, $text, $before, $request->position);
        if (null !== $argumentItems) {
            return $argumentItems;
        }
        $syntax = $this->maskStringContents($before);
        if (1 === preg_match('/\|\s*([A-Za-z_][A-Za-z0-9_]*)?$/', $syntax, $matches)) {
            $kind = TwigCallableKind::Filter;
            $prefix = $matches[1] ?? '';
        } elseif (1 === preg_match('/(?<![\w.\'\"|])([A-Za-z_][A-Za-z0-9_]*)$/', $syntax, $matches)) {
            $kind = TwigCallableKind::Function;
            $prefix = $matches[1];
        } else {
            return null;
        }
        $start = $this->converter->toPosition($text, $offset - \strlen($prefix));
        $items = [];
        foreach ($this->indexes->forProject($request->project)->names($kind) as $name) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            $items[] = [
                'label' => $name,
                'kind' => 3,
                'detail' => 'Twig '.$kind->value,
                'textEdit' => $this->protocol->textEdit(new Range($start, $request->position), $name),
            ];
        }

        return $items;
    }

    /** @return list<array<string, mixed>>|null */
    private function completeArgumentNames(Project $project, string $text, string $before, Position $position): ?array
    {
        $context = $this->callContext($before);
        if (null === $context) {
            return null;
        }
        $kind = $context['filter'] ? TwigCallableKind::Filter : TwigCallableKind::Function;
        $parameters = $this->callableParameters($project, $kind, $context['callee']);
        if (null === $parameters) {
            return null;
        }
        $used = [];
        foreach ($this->argumentSegments($context['arguments']) as $argument) {
            if (1 === preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*[:=](?![=>])/', $argument, $match)) {
                $used[] = $match[1];
            }
        }
        $prefix = $context['prefix'];
        $start = $this->converter->toPosition($text, $this->converter->toByteOffset($text, $position) - \strlen($prefix));
        $items = [];
        foreach ($parameters['nameable'] as $name) {
            if (!str_starts_with($name, $prefix) || \in_array($name, $used, true)) {
                continue;
            }
            $items[] = [
                'label' => $name,
                'kind' => 5,
                'detail' => 'Twig '.$kind->value.' argument',
                'textEdit' => $this->protocol->textEdit(new Range($start, $position), $name),
            ];
        }

        return $items;
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

    public function name(): string
    {
        return 'twig-callable';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        if (null === $request || 'twig' !== $request->document->languageId) {
            return null;
        }
        $text = $request->document->text;
        $masked = $this->comments->mask($text);
        $syntax = $this->maskStringContents($masked);
        $diagnostics = [];
        $resolved = [];
        preg_match_all('/(\|\s*)?([A-Za-z_][A-Za-z0-9_]*)\s*\(([^()]*)\)/', $syntax, $calls, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);
        foreach ($calls[2] as $index => [$callee, $calleeOffset]) {
            if (!\is_string($callee) || !$this->references->insideDirective($masked, $calleeOffset)) {
                continue;
            }
            $kind = null !== ($calls[1][$index][0] ?? null) ? TwigCallableKind::Filter : TwigCallableKind::Function;
            if (TwigCallableKind::Function === $kind && str_ends_with(rtrim(substr($syntax, 0, $calleeOffset)), '.')) {
                continue;
            }
            $parameters = $resolved[$kind->value.'|'.$callee] ??= $this->callableParameters($request->project, $kind, $callee) ?? false;
            $parameters = false === $parameters ? null : $parameters;
            if (null === $parameters || $parameters['variadic']) {
                continue;
            }
            $arguments = $calls[3][$index][0] ?? '';
            $argumentsOffset = $calls[3][$index][1] ?? 0;
            // hash literals carry their own keys, as in {width: 10}
            $scrubbed = preg_replace_callback('/\{[^{}]*\}/', static fn (array $hash): string => str_repeat(' ', \strlen($hash[0])), $arguments) ?? '';
            preg_match_all('/(?<=^|,)\s*([A-Za-z_][A-Za-z0-9_]*)\s*[:=](?![=>])/', $scrubbed, $named, \PREG_OFFSET_CAPTURE);
            foreach ($named[1] as [$name, $nameOffset]) {
                if (\in_array($name, $parameters['all'], true)) {
                    continue;
                }
                $diagnostics[] = $this->protocol->diagnostic(
                    new Range(
                        $this->converter->toPosition($text, $argumentsOffset + $nameOffset),
                        $this->converter->toPosition($text, $argumentsOffset + $nameOffset + \strlen($name)),
                    ),
                    1,
                    'twig_callable.unknown_argument',
                    \sprintf('Unknown argument "%s" for Twig %s "%s".', $name, $kind->value, $callee),
                );
            }
        }

        return $diagnostics;
    }

    /** @return array{filter: bool, callee: string, arguments: string, prefix: string}|null */
    private function callContext(string $before): ?array
    {
        $stack = [];
        $quote = null;
        $escaped = false;
        for ($offset = 0, $length = \strlen($before); $offset < $length; ++$offset) {
            $character = $before[$offset];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }
                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
            } elseif (\in_array($character, ['(', '[', '{'], true)) {
                $stack[] = [$character, $offset];
            } elseif ([] !== $stack && $character === ['(' => ')', '[' => ']', '{' => '}'][$stack[array_key_last($stack)][0]]) {
                array_pop($stack);
            }
        }
        if (null !== $quote || [] === $stack || '(' !== $stack[array_key_last($stack)][0]) {
            return null;
        }
        $open = $stack[array_key_last($stack)][1];
        $head = substr($before, 0, $open);
        if (1 === preg_match('/\|\s*([A-Za-z_][A-Za-z0-9_]*)\s*$/', $head, $callee)) {
            $filter = true;
        } elseif (1 === preg_match('/(?<![\w.|])([A-Za-z_][A-Za-z0-9_]*)\s*$/', $head, $callee)) {
            $filter = false;
        } else {
            return null;
        }
        $arguments = substr($before, $open + 1);
        $segments = $this->argumentSegments($arguments);
        $current = $segments[\count($segments) - 1];
        if (1 !== preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)?$/', $current, $prefix)) {
            return null;
        }

        return [
            'filter' => $filter,
            'callee' => $callee[1],
            'arguments' => $arguments,
            'prefix' => $prefix[1] ?? '',
        ];
    }

    /** @return list<string> */
    private function argumentSegments(string $arguments): array
    {
        $segments = [];
        $start = 0;
        $stack = [];
        $quote = null;
        $escaped = false;
        for ($offset = 0, $length = \strlen($arguments); $offset < $length; ++$offset) {
            $character = $arguments[$offset];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }
                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
            } elseif (\in_array($character, ['(', '[', '{'], true)) {
                $stack[] = ['(' => ')', '[' => ']', '{' => '}'][$character];
            } elseif ([] !== $stack && $character === $stack[array_key_last($stack)]) {
                array_pop($stack);
            } elseif (',' === $character && [] === $stack) {
                $segments[] = substr($arguments, $start, $offset - $start);
                $start = $offset + 1;
            }
        }
        $segments[] = substr($arguments, $start);

        return $segments;
    }

    private function maskStringContents(string $text): string
    {
        $masked = $text;
        $quote = null;
        $escaped = false;
        for ($offset = 0, $length = \strlen($text); $offset < $length; ++$offset) {
            $character = $text[$offset];
            if (null === $quote) {
                if (\in_array($character, ["'", '"'], true)) {
                    $quote = $character;
                }
                continue;
            }
            if ($escaped) {
                $escaped = false;
            } elseif ('\\' === $character) {
                $escaped = true;
            } elseif ($quote === $character) {
                $quote = null;
                continue;
            }
            if ("\n" !== $character) {
                $masked[$offset] = ' ';
            }
        }

        return $masked;
    }

    /**
     * Argument names come from the resolved PHP callable. Injected
     * parameters, the environment, the context, and the filtered value,
     * cannot be passed by name from Twig.
     *
     * @return array{all: list<string>, nameable: list<string>, variadic: bool}|null
     */
    private function callableParameters(Project $project, TwigCallableKind $kind, string $callee): ?array
    {
        $method = null;
        $matchedDeclaration = null;
        foreach ($this->indexes->forProject($project)->declarations($kind, $callee) as $declaration) {
            $method = $this->callableMethods($project, $declaration)[0]['method'] ?? null;
            if ($method instanceof PhpMethodDeclaration) {
                $matchedDeclaration = $declaration;
                break;
            }
        }
        if (!$method instanceof PhpMethodDeclaration || null === $matchedDeclaration) {
            return null;
        }
        $open = strpos($method->signature, '(');
        $close = strrpos($method->signature, ')');
        if (false === $open || false === $close || $close < $open) {
            return null;
        }
        $parameterList = substr($method->signature, $open + 1, $close - $open - 1);
        preg_match_all('/(?:([\\\\\w|?]+)\s+)?(\.\.\.)?\$([A-Za-z_][A-Za-z0-9_]*)/', $parameterList, $matches, \PREG_SET_ORDER);
        $all = [];
        $types = [];
        $phpVariadic = false;
        foreach ($matches as $match) {
            $all[] = $match[3];
            $types[] = $match[1];
            $phpVariadic = $phpVariadic || '' !== $match[2];
        }
        if ($matchedDeclaration->optionsKnown) {
            $skip = (int) $matchedDeclaration->needsCharset
                + (int) $matchedDeclaration->needsEnvironment
                + (int) $matchedDeclaration->needsContext
                + (int) $matchedDeclaration->needsIsSandboxed;
        } else {
            $skip = 'charset' === ($all[0] ?? '') ? 1 : 0;
            if (str_contains($types[$skip] ?? '', 'Environment')) {
                ++$skip;
            }
            if ('array' === ($types[$skip] ?? '') && 'context' === ($all[$skip] ?? '')) {
                ++$skip;
            }
            if ('isSandboxed' === ($all[$skip] ?? '')) {
                ++$skip;
            }
        }
        if (TwigCallableKind::Filter === $kind) {
            ++$skip;
        }
        $variadic = $phpVariadic || $matchedDeclaration->variadic;
        $nameable = \array_slice($all, $skip);
        if ($variadic) {
            array_pop($nameable);
        }

        return ['all' => $all, 'nameable' => $nameable, 'variadic' => $variadic || !$matchedDeclaration->optionsKnown];
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
            $declaration = $callables[$name];
            $value .= "\n\nCallable: `".$name.'`';
            $method = $this->callableMethods($project, $declaration)[0]['method'] ?? null;
            if ($method instanceof PhpMethodDeclaration) {
                $value .= "\n\n```php\n".$method->signature."\n```";
                if (null !== $method->description) {
                    $value .= "\n\n".$method->description;
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
                $locations[] = $this->protocol->location($declaration->uri, $declaration->range);
                continue;
            }
            foreach ($methods as $method) {
                $locations[] = $this->protocol->location(
                    $method['uri'],
                    new Range(
                        $this->converter->toPosition($method['text'], $method['method']->nameStartOffset),
                        $this->converter->toPosition($method['text'], $method['method']->nameEndOffset),
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

    /** @return list<array{uri: string, text: string, method: PhpMethodDeclaration}> */
    private function callableMethods(Project $project, TwigCallableDeclaration $declaration): array
    {
        if (null === $declaration->className || null === $declaration->method) {
            return [];
        }
        $methods = [];
        foreach ($this->classIndexes->forProject($project)->classDeclarations($declaration->className) as $class) {
            $text = $this->source($project, $class->uri);
            if (null === $text) {
                continue;
            }
            foreach ($this->phpParser->parse($text)->methodDeclarations as $method) {
                if (0 === strcasecmp($declaration->className, $method->className) && 0 === strcasecmp($declaration->method, $method->name)) {
                    $methods[] = ['uri' => $class->uri, 'text' => $text, 'method' => $method];
                }
            }
        }

        return $methods;
    }

    private function source(Project $project, string $uri): ?string
    {
        return $this->reader->read($project, $uri)?->text;
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
