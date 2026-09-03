<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\ProjectPathResolver;
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
        private readonly ProjectPathResolver $projectPaths,
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
        $environment = $this->runtimeConfiguration->environment($request->project);
        $facts = $this->extractor->extractForInteractive(
            SourceDocument::fromDocument($request->document),
            $environment,
        );
        if (null === $facts) {
            return null;
        }
        $relativePath = $this->projectPaths->relative($request->project, $request->document->uri);
        if (null !== $relativePath && !$this->includesEnvironment($relativePath, $environment)) {
            return [];
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

    private function includesEnvironment(string $relativePath, string $environment): bool
    {
        if (preg_match('#^config/(?:packages|routes)/([^/]+)/#D', $relativePath, $matches)) {
            return $environment === $matches[1];
        }
        if (preg_match('#^config/services_([^/]+)\.(?:php|ya?ml)$#iD', $relativePath, $matches)) {
            return $environment === $matches[1];
        }

        return true;
    }
}
