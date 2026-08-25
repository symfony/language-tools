<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class EventDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly LspProtocolMapper $protocol,
        private readonly EventExtractor $extractor,
    ) {
    }

    public function name(): string
    {
        return 'event';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        if (null === $request || 'php' !== $request->document->languageId()) {
            return null;
        }
        $diagnostics = [];
        foreach ($this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text())->invalidListenerMethods() as $listener) {
            $diagnostics[] = $this->protocol->diagnostic(
                $listener->range(),
                1,
                'event.invalid_listener_method',
                \sprintf('Event listener method "%s::%s" does not exist.', $listener->className(), $listener->method()),
            );
        }

        return $diagnostics;
    }
}
