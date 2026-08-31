<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigCallableDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigCallableIndexRegistry $indexes,
        private readonly TwigCallableReferenceExtractor $references,
        private readonly TwigCallableMethodResolver $methods,
        private readonly TwigCallableArgumentAnalyzer $arguments,
        private readonly TwigCommentParser $comments,
    ) {
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
        $masked = $this->comments->mask($request->document->text);
        $calls = [];
        $callables = [];
        foreach ($this->arguments->completeCalls($masked) as $call) {
            if ($call->hasNestedParentheses || !$this->references->insideDirective($masked, $call->calleeOffset)) {
                continue;
            }
            $key = $call->kind->value."\0".$call->callee;
            $calls[] = [$key, $call];
            $callables[$key] ??= [
                'kind' => $call->kind,
                'declarations' => $this->indexes->forProject($request->project)->declarations($call->kind, $call->callee),
            ];
        }
        $parameters = $this->methods->parameters($request->project, $callables);
        $diagnostics = [];
        foreach ($calls as [$key, $call]) {
            $callableParameters = $parameters[$key] ?? null;
            if (null === $callableParameters || $callableParameters->variadic) {
                continue;
            }
            foreach ($call->arguments as $argument) {
                if (null === $argument->name || null === $argument->nameOffset || \in_array($argument->name, $callableParameters->all, true)) {
                    continue;
                }
                $diagnostics[] = $this->protocol->diagnostic(
                    new Range(
                        $this->converter->toPosition($request->document->text, $argument->nameOffset),
                        $this->converter->toPosition($request->document->text, $argument->nameOffset + \strlen($argument->name)),
                    ),
                    1,
                    'twig_callable.unknown_argument',
                    \sprintf('Unknown argument "%s" for Twig %s "%s".', $argument->name, $call->kind->value, $call->callee),
                );
            }
        }

        return $diagnostics;
    }
}
