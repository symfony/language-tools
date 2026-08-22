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
use Symfony\Lsp\Parser\Php\PhpMethodDeclaration;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigCallableProvider implements CompletionProviderInterface, DefinitionProviderInterface, DiagnosticProviderInterface, HoverProviderInterface
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
        if (null === $request || 'twig' !== $request->document->languageId()) {
            return null;
        }
        $text = $request->document->text();
        $offset = $this->converter->toByteOffset($text, $request->position);
        $before = substr($this->comments->mask($text), 0, $offset);
        if (!$this->insideDirective($before)) {
            return null;
        }
        $argumentItems = $this->completeArgumentNames($request->project, $text, $before, $request->position);
        if (null !== $argumentItems) {
            return $argumentItems;
        }
        if (1 === preg_match('/\|\s*([A-Za-z_][A-Za-z0-9_]*)?$/', $before, $matches)) {
            $kind = TwigCallableKind::Filter;
            $prefix = $matches[1] ?? '';
        } elseif (1 === preg_match('/(?<![\w.\'\"|])([A-Za-z_][A-Za-z0-9_]*)$/', $before, $matches)) {
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
        if (1 !== preg_match('/(\|\s*)?([A-Za-z_][A-Za-z0-9_]*)\s*\(([^()]*?)([A-Za-z_][A-Za-z0-9_]*)?$/', $before, $matches)) {
            return null;
        }
        [, $pipe, $callee, $arguments] = $matches;
        $prefix = $matches[4] ?? '';
        // only an argument-list position right after ( or , can start a name
        if ('' !== trim($arguments) && 1 !== preg_match('/,\s*$/', $arguments)) {
            return null;
        }
        $kind = '' !== $pipe ? TwigCallableKind::Filter : TwigCallableKind::Function;
        $parameters = $this->callableParameters($project, $kind, $callee);
        if (null === $parameters) {
            return null;
        }
        preg_match_all('/(?<=\(|,)\s*([A-Za-z_][A-Za-z0-9_]*)\s*[:=]/', '('.$matches[3], $used);
        $start = $this->converter->toPosition($text, $this->converter->toByteOffset($text, $position) - \strlen($prefix));
        $items = [];
        foreach ($parameters['nameable'] as $name) {
            if (!str_starts_with($name, $prefix) || \in_array($name, $used[1], true)) {
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

    public function diagnostics(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        if (null === $request || 'twig' !== $request->document->languageId()) {
            return null;
        }
        $text = $request->document->text();
        $masked = $this->comments->mask($text);
        $diagnostics = [];
        $resolved = [];
        preg_match_all('/(\|\s*)?([A-Za-z_][A-Za-z0-9_]*)\s*\(([^()]*)\)/', $masked, $calls, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);
        foreach ($calls[2] as $index => [$callee, $calleeOffset]) {
            if (!\is_string($callee) || !$this->insideDirective(substr($masked, 0, $calleeOffset))) {
                continue;
            }
            $kind = null !== ($calls[1][$index][0] ?? null) ? TwigCallableKind::Filter : TwigCallableKind::Function;
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
        foreach ($this->indexes->forProject($project)->declarations($kind, $callee) as $declaration) {
            $method = $this->callableMethods($project, $declaration)[0]['method'] ?? null;
            if ($method instanceof PhpMethodDeclaration) {
                break;
            }
        }
        if (!$method instanceof PhpMethodDeclaration) {
            return null;
        }
        $open = strpos($method->signature(), '(');
        $close = strrpos($method->signature(), ')');
        if (false === $open || false === $close || $close < $open) {
            return null;
        }
        $parameterList = substr($method->signature(), $open + 1, $close - $open - 1);
        preg_match_all('/(?:([\\\\\w|?]+)\s+)?(\.\.\.)?\$([A-Za-z_][A-Za-z0-9_]*)/', $parameterList, $matches, \PREG_SET_ORDER);
        $all = [];
        $variadic = false;
        $types = [];
        foreach ($matches as $match) {
            $all[] = $match[3];
            $types[] = $match[1];
            $variadic = $variadic || '' !== $match[2];
        }
        $skip = 0;
        if (str_contains($types[0] ?? '', 'Environment')) {
            ++$skip;
        }
        if ('array' === ($types[$skip] ?? '') && 'context' === ($all[$skip] ?? '')) {
            ++$skip;
        }
        if (TwigCallableKind::Filter === $kind) {
            ++$skip;
        }

        return ['all' => $all, 'nameable' => \array_slice($all, $skip), 'variadic' => $variadic];
    }

    private function insideDirective(string $before): bool
    {
        $open = -1;
        foreach (['{{', '{%'] as $token) {
            $position = strrpos($before, $token);
            if (false !== $position) {
                $open = max($open, $position);
            }
        }
        if (-1 === $open) {
            return false;
        }
        foreach (['}}', '%}'] as $token) {
            $position = strrpos($before, $token);
            if (false !== $position && $position > $open) {
                return false;
            }
        }

        return true;
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
            $text = $this->source($project, $class->uri());
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
