<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TwigComponentDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigComponentIndexRegistry $indexes,
        private readonly TemplateIndexRegistry $templates,
        private readonly TwigComponentExtractor $extractor,
        private readonly TwigComponentResolver $components,
    ) {
    }

    public function name(): string
    {
        return 'twig-component';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        if (null === $request || 'twig' !== $request->document->languageId()) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        if (!$index->isComplete() || !$index->isRuntimeComplete()) {
            return null;
        }
        if (!$this->templates->forProject($request->project)->isComplete()) {
            return null;
        }
        $diagnostics = [];
        foreach ($this->extractor->extract($request->project, $request->document->uri(), 'twig', $request->document->text())->references() as $reference) {
            $name = $reference->name();
            if (null !== $index->get($name) || $index->hasRuntimeName($name) || $this->components->anonymousTemplateExists($request->project, $name)) {
                continue;
            }
            $diagnostics[] = $this->protocol->diagnostic($reference->range(), 1, 'twig_component.not_found', \sprintf('Twig component "%s" does not exist.', $name));
        }

        return $diagnostics;
    }
}
