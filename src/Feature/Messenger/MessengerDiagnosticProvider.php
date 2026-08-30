<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpTypedVariable;
use Symfony\Lsp\Parser\Php\PhpTypedVariableKind;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class MessengerDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly MessengerIndexRegistry $indexes,
        private readonly MessengerExtractor $extractor,
        private readonly PhpParserInterface $phpParser,
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
        foreach ($this->extractor->extract($request->document->uri, $request->document->languageId, $request->document->text)->symbols as $symbol) {
            if ($symbol->declaration || MessengerSymbolKind::Message === $symbol->kind) {
                continue;
            }
            $known = MessengerSymbolKind::Bus === $symbol->kind ? null !== $index->bus($symbol->name) : null !== $index->transport($symbol->name);
            if (!$known) {
                $diagnostics[] = $this->protocol->diagnostic($symbol->range, 1, MessengerSymbolKind::Bus === $symbol->kind ? 'messenger.unknown_bus' : 'messenger.unknown_transport', \sprintf('Unknown Messenger %s "%s".', strtolower($symbol->kind->name), $symbol->name));
            }
        }
        if ('php' !== $request->document->languageId) {
            return $diagnostics;
        }
        $scalarTypes = ['array', 'bool', 'callable', 'float', 'int', 'never', 'resource', 'string', 'void'];
        $php = $this->phpParser->parse($request->document->text);
        foreach ($php->methodDeclarations as $method) {
            if (!\in_array(strtolower((string) $method->firstParameterType), $scalarTypes, true)) {
                continue;
            }
            $parameters = array_values(array_filter(
                $php->typedVariables,
                static fn (PhpTypedVariable $variable): bool => PhpTypedVariableKind::Parameter === $variable->kind
                    && $method->className === $variable->className
                    && $method->name === $variable->methodName,
            ));
            usort($parameters, static fn (PhpTypedVariable $left, PhpTypedVariable $right): int => $left->nameStartOffset <=> $right->nameStartOffset);
            $parameter = $parameters[0] ?? null;
            if (null === $parameter) {
                continue;
            }
            foreach ($index->handlersByClass($method->className) as $handler) {
                if ($method->name !== $handler->method) {
                    continue;
                }
                $range = new Range(
                    $this->converter->toPosition($request->document->text, $parameter->nameStartOffset),
                    $this->converter->toPosition($request->document->text, $parameter->nameEndOffset),
                );
                $diagnostics[] = $this->protocol->diagnostic($range, 1, 'messenger.invalid_handler_signature', \sprintf('Messenger handler "%s::%s" cannot accept message "%s".', $handler->className, $handler->method, $handler->message));
            }
        }

        return $diagnostics;
    }
}
