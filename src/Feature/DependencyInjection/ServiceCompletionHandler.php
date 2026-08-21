<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class ServiceCompletionHandler implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly PositionConverter $positionConverter,
        private readonly LspProtocolMapper $protocol,
        private readonly ServiceIndexRegistry $serviceIndexes,
        private readonly ParameterIndexRegistry $parameterIndexes,
        private readonly DependencyInjectionSourceIndexRegistry $sourceIndexes,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->documentContextResolver->resolvePositioned($params);
        if (null === $request || !\in_array($request->document->languageId(), ['php', 'yaml'], true)) {
            return null;
        }

        $parameterContext = 'yaml' === $request->document->languageId()
            ? ParameterCompletionContext::fromYaml($request->document->text(), $request->position, $this->positionConverter)
            : ParameterCompletionContext::fromPhp($request->document->text(), $request->position, $this->positionConverter);
        if (null !== $parameterContext) {
            return $this->completeParameters($request->project, $parameterContext);
        }

        $serviceContext = 'yaml' === $request->document->languageId()
            ? ServiceCompletionContext::fromYaml($request->document->text(), $request->position, $this->positionConverter)
            : ServiceCompletionContext::fromPhp($request->document->text(), $request->position, $this->positionConverter);
        if (null === $serviceContext) {
            return null;
        }

        return $this->completeServices($request->project, $serviceContext);
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
                'textEdit' => $this->protocol->textEdit($context->replacementRange(), $service->id()),
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
                'textEdit' => $this->protocol->textEdit($context->replacementRange(), $id),
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
                'textEdit' => $this->protocol->textEdit(
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
                'textEdit' => $this->protocol->textEdit($context->replacementRange(), $context->completionText($name)),
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
}
