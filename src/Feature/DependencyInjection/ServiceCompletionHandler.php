<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\PositionedDocumentContext;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Parser\Yaml\YamlCommentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class ServiceCompletionHandler implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documentContextResolver,
        private readonly PositionConverter $positionConverter,
        private readonly LspProtocolMapper $protocol,
        private readonly DependencyInjectionProjectLookup $lookup,
        private readonly PhpAutowireArgumentResolver $autowireArguments,
        private readonly YamlCommentParser $yamlComments,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->documentContextResolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }

        return match ($request->document->languageId) {
            'yaml' => $this->completeYaml($request),
            'php' => $this->completePhp($request),
            default => null,
        };
    }

    /** @return list<array<array-key, mixed>>|null */
    private function completeYaml(PositionedDocumentContext $request): ?array
    {
        $text = $this->yamlComments->mask($request->document->text);
        $parameterContext = ParameterCompletionContext::fromYaml($text, $request->position, $this->positionConverter);
        if (null !== $parameterContext) {
            return $this->completeParameters($request->project, $parameterContext);
        }

        $serviceContext = ServiceCompletionContext::fromYaml($text, $request->position, $this->positionConverter);

        return null === $serviceContext ? null : $this->completeServices($request->project, $serviceContext);
    }

    /** @return list<array<array-key, mixed>>|null */
    private function completePhp(PositionedDocumentContext $request): ?array
    {
        $text = $request->document->text;
        $argument = $this->autowireArguments->resolve($text, $this->positionConverter->toByteOffset($text, $request->position));
        if (null === $argument) {
            return null;
        }

        $parameterContext = ParameterCompletionContext::fromPhpAutowire($argument, $text, $request->position, $this->positionConverter);
        if (null !== $parameterContext) {
            return $this->completeParameters($request->project, $parameterContext);
        }

        $serviceContext = ServiceCompletionContext::fromPhpAutowire($argument, $text, $request->position, $this->positionConverter);

        return null === $serviceContext ? null : $this->completeServices($request->project, $serviceContext);
    }

    /** @return list<array<array-key, mixed>> */
    private function completeServices(Project $project, ServiceCompletionContext $context): array
    {
        $items = [];
        foreach ($this->lookup->matchingServices($project, $context->prefix) as $service) {
            $items[] = [
                'label' => $service->id,
                'kind' => 18,
                'detail' => $this->serviceDetail($service),
                'textEdit' => $this->protocol->textEdit($context->replacementRange, $service->id),
            ];
        }

        return $items;
    }

    /** @return list<array<array-key, mixed>> */
    private function completeParameters(Project $project, ParameterCompletionContext $context): array
    {
        $items = [];
        foreach ($this->lookup->matchingParameters($project, $context->prefix) as $parameter) {
            $items[] = [
                'label' => $parameter->name,
                'kind' => 12,
                'detail' => null !== $parameter->deprecation ? 'Deprecated Symfony parameter' : 'Symfony parameter',
                'textEdit' => $this->protocol->textEdit(
                    $context->replacementRange,
                    $context->completionText($parameter->name),
                ),
            ];
        }

        return $items;
    }

    private function serviceDetail(Service $service): string
    {
        if (null !== $service->alias) {
            return 'Alias of '.$service->alias;
        }

        return $service->className ?? 'Symfony service';
    }
}
