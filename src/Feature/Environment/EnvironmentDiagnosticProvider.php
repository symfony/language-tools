<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class EnvironmentDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly LspProtocolMapper $protocol,
        private readonly EnvironmentIndexRegistry $indexes,
        private readonly EnvironmentProcessorChainValidator $processorChainValidator,
    ) {
    }

    public function name(): string
    {
        return 'environment';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $facts = $index->factsForUri($request->document->uri);
        if (!$facts instanceof EnvironmentSourceFacts) {
            return [];
        }
        $diagnostics = [];
        foreach ($facts->references as $reference) {
            foreach ($this->processorChainValidator->validate($reference->processors, $index) as $issue) {
                $diagnostics[] = $this->protocol->diagnostic($reference->range, 1, $issue->code, $issue->message);
            }
        }
        foreach ($facts->malformedExpressions as $expression) {
            $diagnostics[] = $this->protocol->diagnostic($expression->range, 1, 'env.malformed_chain', 'Malformed environment expression; expected ")%".');
        }

        return $diagnostics;
    }
}
