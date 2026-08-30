<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class TranslationProvider implements CompletionProviderInterface, DefinitionProviderInterface, DiagnosticProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly TranslationIndexRegistry $indexes,
        private readonly TranslationExtractor $extractor,
        private readonly TranslationConfigurationRegistry $configuration,
        private readonly TwigCommentParser $commentParser,
        private readonly PhpCommentParserInterface $phpComments,
        private readonly TranslationReferenceResolver $referenceResolver,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }

        $context = TranslationCompletionContext::create(
            $request->document->languageId,
            match ($request->document->languageId) {
                'twig' => $this->commentParser->mask($request->document->text),
                'php' => $this->phpComments->mask($request->document->text),
                default => $request->document->text,
            },
            $request->position,
            $this->converter,
        );
        if (null === $context) {
            return null;
        }

        $index = $this->indexes->forProject($request->project);
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
            'textEdit' => $this->protocol->textEdit($context->range(), $this->completionValue($context, $value)),
        ], $values);
    }

    private function completionValue(TranslationCompletionContext $context, string $value): string
    {
        if ('placeholder' === $context->kind()) {
            return $value.'%';
        }
        if (null === $quote = $context->quote()) {
            return $value;
        }

        return strtr($value, ['\\' => '\\\\', $quote => '\\'.$quote]);
    }

    public function hover(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }

        $reference = $resolved->reference;
        $index = $this->indexes->forProject($resolved->project);
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

        return $this->protocol->markdownHover(\sprintf(
            "Translation: `%s`\n\nDomain: `%s`\n\nLocales: `%s`\n\nMessage: %s",
            $reference->key(),
            $reference->domain(),
            implode('`, `', $locales),
            $item->message(),
        ));
    }

    public function definition(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }

        return array_map(fn (TranslationDeclaration $declaration): array => $this->protocol->location($declaration->uri(), $declaration->range()), $this->indexes->forProject($resolved->project)->declarations($resolved->reference->domain(), $resolved->reference->key()));
    }

    public function references(array $params): ?array
    {
        $resolved = $this->resolve($params);
        if (null === $resolved) {
            return null;
        }

        return array_map(fn (TranslationReference $item): array => $this->protocol->location($item->uri(), $item->range()), $this->indexes->forProject($resolved->project)->references($resolved->reference->domain(), $resolved->reference->key()));
    }

    public function name(): string
    {
        return 'translation';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request) {
            return null;
        }

        $index = $this->indexes->forProject($request->project);
        $diagnostics = [];
        foreach ($this->extractor->extract($request->document->uri, $request->document->languageId, $request->document->text)->references() as $reference) {
            if ($index->isComplete() && !\in_array($reference->domain(), $index->domains(), true)) {
                if ($this->configuration->missingKeyDiagnostics($request->project)) {
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
                if ($this->configuration->missingKeyDiagnostics($request->project) && $index->isComplete()) {
                    $diagnostics[] = $this->diagnostic(
                        $reference,
                        'translation.not_found',
                        \sprintf('Translation "%s" does not exist in domain "%s".', $reference->key(), $reference->domain()),
                    );
                }

                continue;
            }

            // extra parameters are legal and dynamic parameters are unknown,
            // so only placeholders the message expects but a literal parameter
            // list does not provide are proven mistakes
            $expected = ($messages[0] ?? $declarations[0])->placeholders();
            $provided = $reference->placeholders();
            if (null !== $provided && [] !== array_diff($expected, $provided)) {
                $diagnostics[] = $this->diagnostic(
                    $reference,
                    'translation.placeholders',
                    'Translation placeholders do not match the message.',
                );
            }
        }

        return $diagnostics;
    }

    /** @param array<array-key, mixed> $params */
    private function resolve(array $params): ?ResolvedTranslationReference
    {
        return $this->referenceResolver->resolve($params);
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
        return $this->protocol->diagnostic($reference->range(), 1, $code, $message);
    }
}
