<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigCallableDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigCallableIndexRegistry $indexes,
        private readonly TwigCallableMethodResolver $methods,
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
        $index = $this->indexes->forProject($request->project);
        $facts = $index->factsForUri($request->document->uri);
        if (!$facts instanceof TwigCallableSourceFacts) {
            return [];
        }
        $calls = [];
        $callables = [];
        foreach ($facts->calls as $call) {
            $key = $call->kind->value."\0".$call->name;
            $calls[] = [$key, $call];
            $callables[$key] ??= [
                'kind' => $call->kind,
                'declarations' => $index->declarations($call->kind, $call->name),
            ];
        }
        $parameters = $this->methods->parameters($request->project, $callables);
        $diagnostics = [];
        foreach ($calls as [$key, $call]) {
            $callableParameters = $parameters[$key] ?? null;
            if (null === $callableParameters || !$callableParameters->reliable || $callableParameters->variadic) {
                continue;
            }
            foreach ($call->arguments as $argument) {
                if (\in_array($argument->name, $callableParameters->all, true)) {
                    continue;
                }
                $diagnostics[] = $this->protocol->diagnostic(
                    $argument->range,
                    1,
                    'twig_callable.unknown_argument',
                    \sprintf('Unknown argument "%s" for Twig %s "%s".', $argument->name, $call->kind->value, $call->name),
                );
            }
        }

        return $diagnostics;
    }
}
