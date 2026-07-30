<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;

final class ServiceCompletionHandler implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly PositionConverter $positionConverter,
        private readonly ServiceIndexRegistry $indexes,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->documentContextResolver->resolve($params);
        if (null === $request) {
            return null;
        }

        [$document, $project, $position] = $request;
        if ('yaml' !== $document->languageId()) {
            return null;
        }

        $context = ServiceCompletionContext::fromYaml(
            $document->text(),
            $position,
            $this->positionConverter,
        );
        if (null === $context) {
            return null;
        }

        return array_map(
            fn (Service $service): array => [
                'label' => $service->id(),
                'kind' => 18,
                'detail' => $this->detail($service),
                'textEdit' => $this->textEdit($context->replacementRange(), $service->id()),
            ],
            $this->indexes->forProject($project)->matching($context->prefix()),
        );
    }

    private function detail(Service $service): string
    {
        if (null !== $service->alias()) {
            return 'Alias of '.$service->alias();
        }

        return $service->className() ?? 'Symfony service';
    }

    /**
     * @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string}
     */
    private function textEdit(Range $range, string $newText): array
    {
        return [
            'range' => [
                'start' => [
                    'line' => $range->start()->line(),
                    'character' => $range->start()->character(),
                ],
                'end' => [
                    'line' => $range->end()->line(),
                    'character' => $range->end()->character(),
                ],
            ],
            'newText' => $newText,
        ];
    }
}
