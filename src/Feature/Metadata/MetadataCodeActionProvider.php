<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class MetadataCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $documents,
        private readonly LspProtocolMapper $protocol,
        private readonly MetadataIndexRegistry $indexes,
        private readonly MetadataSourceIndexRegistry $sourceIndexes,
        private readonly ProjectPathResolver $paths,
        private readonly UnknownNameCodeActionBuilder $unknownNames,
    ) {
    }

    public function actions(array $params): ?array
    {
        $request = $this->documents->resolveDocument($params);
        $context = $params['context'] ?? null;
        if (null === $request || !\is_array($context) || !\in_array($request->document->languageId, ['php', 'yaml'], true)
            || !$this->paths->isApplicationOwned($request->project, $request->document->uri)
        ) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $facts = $this->sourceIndexes->forProject($request->project)->factsForUri($request->document->uri);
        $actions = [];
        foreach (\is_array($context['diagnostics'] ?? null) ? $context['diagnostics'] : [] as $diagnostic) {
            if (!\is_array($diagnostic) || !\in_array($diagnostic['code'] ?? null, ['form.unknown_option', 'validation.unknown_constraint_option'], true)
                || !\is_array($diagnostic['range'] ?? null)
            ) {
                continue;
            }
            if ('form.unknown_option' === $diagnostic['code']) {
                if (!$index->formsComplete()) {
                    continue;
                }
                foreach ($facts instanceof MetadataSourceFacts ? $facts->formOptions : [] as $reference) {
                    if (!$this->protocol->sameRange($reference->range, $diagnostic['range'])) {
                        continue;
                    }
                    $type = $index->formType($reference->className);
                    if (null !== $type && !\in_array($reference->option, $type->options, true)) {
                        array_push($actions, ...$this->unknownNames->replacements($request->document, $diagnostic, $reference->range, $reference->option, $type->options));
                    }
                    break;
                }
            } else {
                if (!$index->constraintsComplete()) {
                    continue;
                }
                foreach ($facts instanceof MetadataSourceFacts ? $facts->constraintOptions : [] as $reference) {
                    if (!$this->protocol->sameRange($reference->range, $diagnostic['range'])) {
                        continue;
                    }
                    $constraint = $index->constraint($reference->constraint);
                    if (null !== $constraint && !\in_array($reference->option, $constraint->options, true)) {
                        array_push($actions, ...$this->unknownNames->replacements($request->document, $diagnostic, $reference->range, $reference->option, $constraint->options));
                    }
                    break;
                }
            }
        }

        return $actions;
    }
}
