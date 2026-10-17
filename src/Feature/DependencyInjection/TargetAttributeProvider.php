<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Protocol\CompletionItemKind;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\PositionedRequest;

final class TargetAttributeProvider implements CompletionProviderInterface, HoverProviderInterface
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly LspProtocolMapper $protocol,
        private readonly DependencyInjectionProjectLookup $lookup,
        private readonly PhpTargetArgumentResolver $targetArguments,
    ) {
    }

    public function complete(PositionedRequest $request): array
    {
        $argument = $this->argument($request);
        if (null === $argument) {
            return [];
        }

        $range = new Range(
            $this->positionConverter->toPosition($request->document->text, $argument->valueStartOffset),
            $request->position,
        );
        $items = [];
        foreach ($this->lookup->autowiringVariants($request->project, $argument->type) as $variant) {
            if (str_starts_with($variant->name, $argument->prefix)) {
                $items[] = $this->protocol->completionItem(
                    $variant->name,
                    CompletionItemKind::Reference,
                    $variant->serviceId,
                    $this->protocol->textEdit($range, $variant->name),
                );
            }
        }

        return $items;
    }

    public function hover(PositionedRequest $request): ?array
    {
        $argument = $this->argument($request);
        if (null === $argument) {
            return null;
        }

        $variant = $this->lookup->autowiringVariant($request->project, $argument->type, $argument->name);
        if (null === $variant) {
            return null;
        }

        $details = [
            \sprintf('Autowiring alias: `%s`', $variant->aliasId),
            \sprintf('Service: `%s`', $variant->serviceId),
        ];
        $className = $this->lookup->service($request->project, $variant->serviceId)?->className;
        if (null !== $className) {
            $details[] = \sprintf('Class: `%s`', $className);
        }

        return $this->protocol->markdownHover(implode("\n\n", $details));
    }

    private function argument(PositionedRequest $request): ?PhpTargetArgument
    {
        if ('php' !== $request->document->languageId) {
            return null;
        }

        return $this->targetArguments->resolve($request->document->text, $request->offset);
    }
}
