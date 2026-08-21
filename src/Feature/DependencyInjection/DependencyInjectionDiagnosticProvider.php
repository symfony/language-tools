<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class DependencyInjectionDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly LspProtocolMapper $protocol,
        private readonly ServiceIndexRegistry $serviceIndexes,
        private readonly ParameterIndexRegistry $parameterIndexes,
        private readonly DependencyInjectionSourceIndexRegistry $sourceIndexes,
        private readonly YamlDependencyInjectionExtractor $yamlExtractor,
        private readonly PhpAutowireReferenceExtractor $autowireExtractor,
    ) {
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->documentContextResolver->resolveDocument($params);
        if (null === $request || !\in_array($request->document->languageId(), ['php', 'yaml'], true)) {
            return null;
        }

        $references = 'yaml' === $request->document->languageId()
            ? $this->yamlExtractor->extract($request->document->uri(), $request->document->text())->references()
            : $this->autowireExtractor->extract($request->document->uri(), $request->document->text());
        $sourceIndex = $this->sourceIndexes->forProject($request->project);
        $serviceIndex = $this->serviceIndexes->forProject($request->project);
        $parameterIndex = $this->parameterIndexes->forProject($request->project);
        if (!$serviceIndex->isComplete() && !$parameterIndex->isComplete()) {
            return null;
        }

        $diagnostics = [];
        foreach ($references as $reference) {
            if (DependencyInjectionSymbolKind::Service === $reference->kind()) {
                if ($reference->isOptional()
                    || !$serviceIndex->isComplete()
                    || null !== $serviceIndex->get($reference->name())
                    || [] !== $sourceIndex->serviceDeclarations($reference->name())
                ) {
                    continue;
                }

                $code = 'service.not_found';
                $message = \sprintf('Service "%s" does not exist in the selected environment.', $reference->name());
            } else {
                if (!$parameterIndex->isComplete()
                    || null !== $parameterIndex->get($reference->name())
                    || [] !== $sourceIndex->parameterDeclarations($reference->name())
                ) {
                    continue;
                }

                $code = 'parameter.not_found';
                $message = \sprintf('Parameter "%s" does not exist in the selected environment.', $reference->name());
            }

            $diagnostics[] = $this->protocol->diagnostic($reference->range(), 1, $code, $message);
        }

        return $diagnostics;
    }
}
