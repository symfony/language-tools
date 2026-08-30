<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class ConsoleProvider implements CompletionProviderInterface, DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly ConsoleIndexRegistry $indexes,
        private readonly ConsoleSourceIndexRegistry $sourceIndexes,
        private readonly ConsoleExtractor $extractor,
    ) {
    }

    public function name(): string
    {
        return 'console';
    }

    public function complete(array $params): ?array
    {
        $request = $this->documents->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        $context = $this->extractor->completionContext($request->document->languageId, $request->document->text, $offset);
        if (null === $context) {
            return null;
        }

        $sourceDefinition = $this->sourceIndexes->forProject($request->project)->definition($context->commandClass());
        $runtimeDefinition = $this->indexes->forProject($request->project)->command($context->commandClass());
        if (!$sourceDefinition->isCommand() && null === $runtimeDefinition) {
            return [];
        }
        $names = ConsoleInputKind::Argument === $context->kind()
            ? $sourceDefinition->arguments()
            : $sourceDefinition->options();
        if (null !== $runtimeDefinition) {
            $names = [...$names, ...(ConsoleInputKind::Argument === $context->kind() ? $runtimeDefinition->arguments() : $runtimeDefinition->options())];
        }
        $names = array_values(array_unique($names));
        sort($names);

        $items = [];
        foreach ($names as $name) {
            if (!str_starts_with($name, $context->prefix())) {
                continue;
            }
            $items[] = [
                'label' => $name,
                'detail' => ConsoleInputKind::Argument === $context->kind() ? 'Console input argument' : 'Console input option',
                'kind' => 12,
                'textEdit' => $this->protocol->textEdit($context->range(), $name),
            ];
        }

        return $items;
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        if (null === $request || 'php' !== $request->document->languageId) {
            return null;
        }
        $runtimeIndex = $this->indexes->forProject($request->project);
        if (!$runtimeIndex->isComplete()) {
            return [];
        }
        $sourceIndex = $this->sourceIndexes->forProject($request->project);
        $diagnostics = [];
        foreach ($this->extractor->extract($request->document->uri, 'php', $request->document->text)->references() as $reference) {
            $runtimeDefinition = $runtimeIndex->command($reference->commandClass());
            $sourceDefinition = $sourceIndex->definition($reference->commandClass());
            if (null === $runtimeDefinition
                || !$runtimeDefinition->isComplete()
                || !$sourceDefinition->isCommand()
                || !$sourceDefinition->isComplete()
            ) {
                continue;
            }
            $knownNames = ConsoleInputKind::Argument === $reference->kind()
                ? [...$runtimeDefinition->arguments(), ...$sourceDefinition->arguments()]
                : [...$runtimeDefinition->options(), ...$sourceDefinition->options()];
            if (\in_array($reference->name(), $knownNames, true)) {
                continue;
            }
            $diagnostics[] = $this->protocol->diagnostic(
                $reference->range(),
                1,
                ConsoleInputKind::Argument === $reference->kind() ? 'console.unknown_argument' : 'console.unknown_option',
                \sprintf('Unknown Console input %s "%s".', $reference->kind()->value, $reference->name()),
            );
        }

        return $diagnostics;
    }
}
