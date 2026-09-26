<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\LspRequestFactory;
use Symfony\Lsp\Runtime\EnvironmentScopeResolver;

final class DependencyInjectionDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly LspRequestFactory $requests,
        private readonly LspProtocolMapper $protocol,
        private readonly ServiceIndexRegistry $serviceIndexes,
        private readonly ParameterIndexRegistry $parameterIndexes,
        private readonly DependencyInjectionSourceIndexRegistry $sourceIndexes,
        private readonly EnvironmentScopeResolver $environments,
    ) {
    }

    public function name(): string
    {
        return 'dependency-injection';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->requests->document($params);
        if (null === $request || !\in_array($request->document->languageId, ['php', 'yaml'], true)) {
            return null;
        }
        $facts = $this->sourceIndexes->forProject($request->project)->factsForUri($request->document->uri);
        if (!$facts instanceof DependencyInjectionSourceFacts) {
            return [];
        }

        $localServices = array_fill_keys(array_map(
            static fn (ServiceDeclaration $declaration): string => $declaration->id,
            array_filter($facts->services, fn (ServiceDeclaration $declaration): bool => $this->environments->includesSection($request->project, $declaration->environment)),
        ), true);
        $localParameters = array_fill_keys(array_map(
            static fn (ParameterDeclaration $declaration): string => $declaration->name,
            array_filter($facts->parameters, fn (ParameterDeclaration $declaration): bool => $this->environments->includesSection($request->project, $declaration->environment)),
        ), true);
        $serviceIndex = $this->serviceIndexes->forProject($request->project);
        $parameterIndex = $this->parameterIndexes->forProject($request->project);
        if (!$serviceIndex->isComplete() && !$parameterIndex->isComplete()) {
            return [];
        }

        $diagnostics = [];
        foreach ($facts->references as $reference) {
            if (!$this->environments->includesSection($request->project, $reference->environment)) {
                continue;
            }
            if (DependencyInjectionSymbolKind::Service === $reference->kind) {
                if ($reference->optional
                    || !$serviceIndex->isComplete()
                    || null !== $serviceIndex->get($reference->name)
                    || isset($localServices[$reference->name])
                ) {
                    continue;
                }

                $code = 'service.not_found';
                $message = \sprintf('Service "%s" does not exist in the selected environment.', $reference->name);
            } else {
                if (!$parameterIndex->isComplete()
                    || null !== $parameterIndex->get($reference->name)
                    || isset($localParameters[$reference->name])
                ) {
                    continue;
                }

                $code = 'parameter.not_found';
                $message = \sprintf('Parameter "%s" does not exist in the selected environment.', $reference->name);
            }

            $diagnostics[] = $this->protocol->diagnostic($reference->range, 1, $code, $message);
        }

        return $diagnostics;
    }
}
