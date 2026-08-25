<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class MessengerDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly MessengerIndexRegistry $indexes,
        private readonly MessengerExtractor $extractor,
        private readonly PhpClassDeclarationExtractor $classExtractor,
    ) {
    }

    public function name(): string
    {
        return 'messenger';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        if (null === $request) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        if (!$index->isComplete()) {
            return [];
        }
        $diagnostics = [];
        foreach ($this->extractor->extract($request->document->uri(), $request->document->languageId(), $request->document->text())->symbols() as $symbol) {
            if ($symbol->isDeclaration() || MessengerSymbolKind::Message === $symbol->kind()) {
                continue;
            }
            $known = MessengerSymbolKind::Bus === $symbol->kind() ? null !== $index->bus($symbol->name()) : null !== $index->transport($symbol->name());
            if (!$known) {
                $diagnostics[] = $this->protocol->diagnostic($symbol->range(), 1, MessengerSymbolKind::Bus === $symbol->kind() ? 'messenger.unknown_bus' : 'messenger.unknown_transport', \sprintf('Unknown Messenger %s "%s".', strtolower($symbol->kind()->name), $symbol->name()));
            }
        }
        if ('php' !== $request->document->languageId()) {
            return $diagnostics;
        }
        $scalarTypes = ['array', 'bool', 'callable', 'float', 'int', 'never', 'resource', 'string', 'void'];
        foreach ($this->classExtractor->extract($request->document->uri(), $request->document->text()) as $class) {
            foreach ($index->handlersByClass($class->className()) as $handler) {
                $pattern = '/\bfunction\s+'.preg_quote($handler->method(), '/').'\s*\(\s*(?:[A-Za-z_][A-Za-z0-9_]*\s+)*(\\??[A-Za-z_][A-Za-z0-9_]*)\s+\$/';
                if (preg_match($pattern, $request->document->text(), $match, \PREG_OFFSET_CAPTURE) && \in_array(strtolower(ltrim($match[1][0], '?')), $scalarTypes, true)) {
                    $start = $match[1][1];
                    $range = new Range($this->converter->toPosition($request->document->text(), $start), $this->converter->toPosition($request->document->text(), $start + \strlen($match[1][0])));
                    $diagnostics[] = $this->protocol->diagnostic($range, 1, 'messenger.invalid_handler_signature', \sprintf('Messenger handler "%s::%s" cannot accept message "%s".', $handler->className(), $handler->method(), $handler->message()));
                }
            }
        }

        return $diagnostics;
    }
}
