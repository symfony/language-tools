<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class ValidationMetadataProvider implements DiagnosticProviderInterface, HoverProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly MetadataIndexRegistry $indexes,
        private readonly MetadataSourceIndexRegistry $sourceIndexes,
        private readonly MetadataExtractor $extractor,
    ) {
    }

    public function hover(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text, $request->position);
        $constraintOptions = 'yaml' === $request->document->languageId
            ? $this->extractor->yamlConstraintOptions($request->document->text)
            : $this->extractor->constraintOptions($request->document->text);
        foreach ($constraintOptions as $option) {
            if (!$this->converter->containsByteOffset($request->document->text, $option['range'], $offset, inclusiveEnd: true)) {
                continue;
            }
            $constraint = $this->indexes->forProject($request->project)->constraint($option['constraint']);

            return null === $constraint ? null : $this->protocol->markdownHover(\sprintf("Constraint option: `%s`\n\nConstraint: `%s`", $option['option'], $constraint->className));
        }

        return null;
    }

    public function name(): string
    {
        return 'validation-metadata';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || !\in_array($request->document->languageId, ['php', 'yaml'], true)) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $facts = $this->sourceIndexes->forProject($request->project)->factsForUri($request->document->uri);
        $diagnostics = [];
        foreach ($facts instanceof MetadataSourceFacts ? $facts->constraintOptions : [] as $option) {
            $constraint = $index->constraint($option->constraint);
            if (null !== $constraint && !\in_array($option->option, $constraint->options, true)) {
                $diagnostics[] = $this->diagnostic($option->range, 'validation.unknown_constraint_option', \sprintf('Unknown option "%s" for constraint "%s".', $option->option, $constraint->name));
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
