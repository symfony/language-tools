<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class DependencyInjectionDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly LspProtocolMapper $protocol,
        private readonly ServiceIndexRegistry $serviceIndexes,
        private readonly ParameterIndexRegistry $parameterIndexes,
        private readonly DependencyInjectionDocumentExtractor $extractor,
        private readonly RuntimeConfiguration $runtimeConfiguration,
    ) {
    }

    public function name(): string
    {
        return 'dependency-injection';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->documentContextResolver->resolveDocument($params);
        if (null === $request) {
            return null;
        }
        $facts = $this->extractor->extractForInteractive(
            $request->document->uri,
            $request->document->languageId,
            $request->document->text,
            $this->runtimeConfiguration->environment($request->project),
        );
        if (null === $facts) {
            return null;
        }

        $localServices = array_fill_keys(array_map(static fn (ServiceDeclaration $declaration): string => $declaration->id, $facts->services), true);
        $localParameters = array_fill_keys(array_map(static fn (ParameterDeclaration $declaration): string => $declaration->name, $facts->parameters), true);
        $serviceIndex = $this->serviceIndexes->forProject($request->project);
        $parameterIndex = $this->parameterIndexes->forProject($request->project);
        if (!$serviceIndex->isComplete() && !$parameterIndex->isComplete()) {
            return null;
        }

        $diagnostics = [];
        foreach ($facts->references as $reference) {
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
