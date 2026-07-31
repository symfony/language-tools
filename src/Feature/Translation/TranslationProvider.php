<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class TranslationProvider implements CompletionProviderInterface, DefinitionProviderInterface, DiagnosticProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly PositionConverter $converter,
        private readonly TranslationIndexRegistry $indexes,
        private readonly TranslationExtractor $extractor,
        private readonly TranslationConfigurationRegistry $configuration,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }

        [$document, $project, $position] = $request;
        $context = TranslationCompletionContext::create(
            $document->languageId(),
            $document->text(),
            $position,
            $this->converter,
        );
        if (null === $context) {
            return null;
        }

        $index = $this->indexes->forProject($project);
        /** @var list<string> $values */
        $values = match ($context->kind()) {
            'domain' => $index->domains(),
            'locale' => $index->locales(),
            'placeholder' => $this->placeholders($index, $context->domain(), $context->key()),
            default => $index->keys($context->domain(), $context->prefix()),
        };
        $values = array_values(array_filter(
            $values,
            static fn (string $value): bool => str_starts_with($value, $context->prefix()),
        ));

        return array_map(fn (string $value): array => [
            'label' => $value,
            'kind' => 12,
            'detail' => 'Symfony translation '.$context->kind(),
            'textEdit' => [
                'range' => $this->range($context->range()),
                'newText' => 'placeholder' === $context->kind() ? $value.'%' : $value,
            ],
        ], $values);
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }

        [$reference, $project] = $resolved;
        $index = $this->indexes->forProject($project);
        $messages = $index->messages($reference->domain(), $reference->key());
        $declarations = $index->declarations($reference->domain(), $reference->key());
        $item = $messages[0] ?? $declarations[0] ?? null;
        if (null === $item) {
            return null;
        }

        $locales = array_values(array_unique([
            ...array_map(static fn (TranslationMessage $message): string => $message->locale(), $messages),
            ...array_map(static fn (TranslationDeclaration $declaration): string => $declaration->locale(), $declarations),
        ]));
        sort($locales);

        return ['contents' => ['kind' => 'markdown', 'value' => \sprintf(
            "Translation: `%s`\n\nDomain: `%s`\n\nLocales: `%s`\n\nMessage: %s",
            $reference->key(),
            $reference->domain(),
            implode('`, `', $locales),
            $item->message(),
        )]];
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }

        [$reference, $project] = $resolved;

        return array_map(fn (TranslationDeclaration $declaration): array => [
            'uri' => $declaration->uri(),
            'range' => $this->range($declaration->range()),
        ], $this->indexes->forProject($project)->declarations($reference->domain(), $reference->key()));
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }

        [$reference, $project] = $resolved;

        return array_map(fn (TranslationReference $item): array => [
            'uri' => $item->uri(),
            'range' => $this->range($item->range()),
        ], $this->indexes->forProject($project)->references($reference->domain(), $reference->key()));
    }

    public function diagnostics(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }

        $document = $this->documents->get($textDocument['uri']);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $document || null === $project) {
            return null;
        }

        $index = $this->indexes->forProject($project);
        $diagnostics = [];
        foreach ($this->extractor->extract($document->uri(), $document->languageId(), $document->text())->references() as $reference) {
            if ($index->isComplete() && !\in_array($reference->domain(), $index->domains(), true)) {
                if ($this->configuration->missingKeyDiagnostics($project)) {
                    $diagnostics[] = $this->diagnostic(
                        $reference,
                        'translation.domain_not_found',
                        \sprintf('Translation domain "%s" does not exist.', $reference->domain()),
                    );
                }

                continue;
            }

            $messages = $index->messages($reference->domain(), $reference->key());
            $declarations = $index->declarations($reference->domain(), $reference->key());
            if ([] === $messages && [] === $declarations) {
                if ($this->configuration->missingKeyDiagnostics($project) && $index->isComplete()) {
                    $diagnostics[] = $this->diagnostic(
                        $reference,
                        'translation.not_found',
                        \sprintf('Translation "%s" does not exist in domain "%s".', $reference->key(), $reference->domain()),
                    );
                }

                continue;
            }

            $expected = ($messages[0] ?? $declarations[0])->placeholders();
            if ([] !== array_diff($expected, $reference->placeholders())
                || [] !== array_diff($reference->placeholders(), $expected)
            ) {
                $diagnostics[] = $this->diagnostic(
                    $reference,
                    'translation.placeholders',
                    'Translation placeholders do not match the message.',
                );
            }
        }

        return $diagnostics;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{TranslationReference, Project}|null
     */
    private function resolve(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }

        [$document, $project, $position] = $request;
        $offset = $this->converter->toByteOffset($document->text(), $position);
        $facts = $this->extractor->extract($document->uri(), $document->languageId(), $document->text());
        foreach ($facts->declarations() as $declaration) {
            $start = $this->converter->toByteOffset($document->text(), $declaration->range()->start());
            $end = $this->converter->toByteOffset($document->text(), $declaration->range()->end());
            if ($offset >= $start && $offset <= $end) {
                return [new TranslationReference(
                    $declaration->key(),
                    $declaration->domain(),
                    $document->uri(),
                    $declaration->range(),
                ), $project];
            }
        }
        foreach ($facts->references() as $reference) {
            $start = $this->converter->toByteOffset($document->text(), $reference->range()->start());
            $end = $this->converter->toByteOffset($document->text(), $reference->range()->end());
            if ($offset >= $start && $offset <= $end) {
                return [$reference, $project];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function placeholders(TranslationIndex $index, string $domain, ?string $key): array
    {
        if (null === $key) {
            return [];
        }
        $item = $index->messages($domain, $key)[0] ?? $index->declarations($domain, $key)[0] ?? null;

        return null === $item ? [] : $item->placeholders();
    }

    /** @return array<array-key, mixed> */
    private function diagnostic(TranslationReference $reference, string $code, string $message): array
    {
        return [
            'range' => $this->range($reference->range()),
            'severity' => 1,
            'source' => 'symfony',
            'code' => $code,
            'message' => $message,
        ];
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    private function range(Range $range): array
    {
        return [
            'start' => ['line' => $range->start()->line(), 'character' => $range->start()->character()],
            'end' => ['line' => $range->end()->line(), 'character' => $range->end()->character()],
        ];
    }
}
