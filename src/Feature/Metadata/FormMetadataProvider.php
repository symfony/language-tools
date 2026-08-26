<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class FormMetadataProvider implements DiagnosticProviderInterface, HoverProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly MetadataIndexRegistry $indexes,
        private readonly MetadataExtractor $extractor,
    ) {
    }

    public function hover(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        foreach ($this->extractor->formOptions($request->document->text()) as $option) {
            if (!$this->converter->containsByteOffset($request->document->text(), $option['range'], $offset, inclusiveEnd: true)) {
                continue;
            }
            $type = $this->indexes->forProject($request->project)->formType($option['class']);
            if (null === $type || !\in_array($option['option'], $type->options(), true)) {
                return null;
            }
            $required = \in_array($option['option'], $type->requiredOptions(), true);

            return $this->protocol->markdownHover(\sprintf("Form option: `%s`\n\nType: `%s`\n\nRequired: %s", $option['option'], $type->className(), $required ? 'yes' : 'no'));
        }

        return null;
    }

    public function name(): string
    {
        return 'form-metadata';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || 'php' !== $request->document->languageId()) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $diagnostics = [];
        foreach ($this->extractor->formOptions($request->document->text()) as $option) {
            $type = $index->formType($option['class']);
            if (null !== $type && !\in_array($option['option'], $type->options(), true)) {
                $diagnostics[] = $this->diagnostic($option['range'], 'form.unknown_option', \sprintf('Unknown option "%s" for form type "%s".', $option['option'], $type->className()));
            }
        }

        return $diagnostics;
    }

    /** @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, severity: int, source: string, code: string, message: string} */
    private function diagnostic(Range $range, string $code, string $message): array
    {
        return $this->protocol->diagnostic($range, 1, $code, $message);
    }
}
