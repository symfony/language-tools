<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Project\Project;

final class ServiceCompletionHandler implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly PositionConverter $positionConverter,
        private readonly ServiceIndexRegistry $serviceIndexes,
        private readonly ParameterIndexRegistry $parameterIndexes,
        private readonly DependencyInjectionSourceIndexRegistry $sourceIndexes,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->documentContextResolver->resolve($params);
        if (null === $request) {
            return null;
        }

        [$document, $project, $position] = $request;
        if (!\in_array($document->languageId(), ['php', 'yaml'], true)) {
            return null;
        }

        $parameterContext = 'yaml' === $document->languageId()
            ? ParameterCompletionContext::fromYaml($document->text(), $position, $this->positionConverter)
            : ParameterCompletionContext::fromPhp($document->text(), $position, $this->positionConverter);
        if (null !== $parameterContext) {
            return $this->completeParameters($project, $parameterContext);
        }

        $serviceContext = 'yaml' === $document->languageId()
            ? ServiceCompletionContext::fromYaml($document->text(), $position, $this->positionConverter)
            : ServiceCompletionContext::fromPhp($document->text(), $position, $this->positionConverter);
        if (null === $serviceContext) {
            return null;
        }

        return $this->completeServices($project, $serviceContext);
    }

    /** @return list<array<array-key, mixed>> */
    private function completeServices(Project $project, ServiceCompletionContext $context): array
    {
        $items = [];
        foreach ($this->serviceIndexes->forProject($project)->matching($context->prefix()) as $service) {
            $items[$service->id()] = [
                'label' => $service->id(),
                'kind' => 18,
                'detail' => $this->serviceDetail($service),
                'textEdit' => $this->textEdit($context->replacementRange(), $service->id()),
            ];
        }

        $sourceIndex = $this->sourceIndexes->forProject($project);
        foreach ($sourceIndex->serviceIds() as $id) {
            if (isset($items[$id]) || !str_starts_with($id, $context->prefix())) {
                continue;
            }

            $declaration = $sourceIndex->serviceDeclarations($id)[0] ?? null;
            $detail = $declaration?->className();
            if (null !== $declaration?->alias()) {
                $detail = 'Alias of '.$declaration->alias();
            }
            $items[$id] = [
                'label' => $id,
                'kind' => 18,
                'detail' => $detail ?? 'Symfony service',
                'textEdit' => $this->textEdit($context->replacementRange(), $id),
            ];
        }
        ksort($items);

        return array_values($items);
    }

    /** @return list<array<array-key, mixed>> */
    private function completeParameters(Project $project, ParameterCompletionContext $context): array
    {
        $items = [];
        foreach ($this->parameterIndexes->forProject($project)->matching($context->prefix()) as $parameter) {
            $items[$parameter->name()] = [
                'label' => $parameter->name(),
                'kind' => 12,
                'detail' => null !== $parameter->deprecation() ? 'Deprecated Symfony parameter' : 'Symfony parameter',
                'textEdit' => $this->textEdit(
                    $context->replacementRange(),
                    $context->completionText($parameter->name()),
                ),
            ];
        }

        foreach ($this->sourceIndexes->forProject($project)->parameterNames() as $name) {
            if (isset($items[$name]) || !str_starts_with($name, $context->prefix())) {
                continue;
            }

            $items[$name] = [
                'label' => $name,
                'kind' => 12,
                'detail' => 'Symfony parameter',
                'textEdit' => $this->textEdit($context->replacementRange(), $context->completionText($name)),
            ];
        }
        ksort($items);

        return array_values($items);
    }

    private function serviceDetail(Service $service): string
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
