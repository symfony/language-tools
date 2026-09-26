<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Feature\CodeActionProviderInterface;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Protocol\CodeActionRequest;

final class MetadataCodeActionProvider implements CodeActionProviderInterface
{
    public function __construct(
        private readonly MetadataIndexRegistry $indexes,
        private readonly MetadataSourceIndexRegistry $sourceIndexes,
        private readonly ProjectPathResolver $paths,
        private readonly UnknownNameCodeActionBuilder $unknownNames,
    ) {
    }

    public function actions(CodeActionRequest $request): array
    {
        if (!\in_array($request->document->languageId, ['php', 'yaml'], true)
            || !$this->paths->isApplicationOwned($request->project, $request->document->uri)
        ) {
            return [];
        }
        $index = $this->indexes->forProject($request->project);
        $facts = $this->sourceIndexes->forProject($request->project)->factsForUri($request->document->uri);
        $facts = $facts instanceof MetadataSourceFacts ? $facts : new MetadataSourceFacts($request->document->uri, []);

        return $this->unknownNames->actions(
            $request,
            ['form.unknown_option', 'validation.unknown_constraint_option'],
            [...$facts->formOptions, ...$facts->constraintOptions],
            static function (FormOptionReference|ConstraintOptionReference $reference, string $code) use ($index): ?array {
                if ($reference instanceof FormOptionReference) {
                    $type = 'form.unknown_option' === $code && $index->formsComplete() ? $index->formType($reference->className) : null;

                    return null === $type || \in_array($reference->option, $type->options, true) ? null : [$reference->option, $type->options];
                }
                $constraint = 'validation.unknown_constraint_option' === $code && $index->constraintsComplete() ? $index->constraint($reference->constraint) : null;

                return null === $constraint || \in_array($reference->option, $constraint->options, true) ? null : [$reference->option, $constraint->options];
            },
        );
    }
}
