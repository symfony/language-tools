<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DefinitionProviderInterface;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Feature\ReferencesProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\LspRequestFactory;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\ReferencesRequest;

final class TwigPhpSymbolProvider implements CompletionProviderInterface, DefinitionProviderInterface, HoverProviderInterface, ReferencesProviderInterface
{
    public function __construct(
        private readonly LspRequestFactory $requests,
        private readonly LspProtocolMapper $protocol,
        private readonly TwigPhpSymbolSourceIndexRegistry $indexes,
        private readonly TwigPhpSymbolExtractor $extractor,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->requests->positioned($params);
        if (null === $request) {
            return null;
        }

        if ('twig' !== $request->document->languageId) {
            return null;
        }
        $text = $request->document->text;
        $context = $this->extractor->completionContext($text, $request->offset);
        if (null === $context) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        if (\in_array($context->kind, [TwigPhpSymbolCompletionKind::ConstantType, TwigPhpSymbolCompletionKind::EnumType], true)) {
            $names = TwigPhpSymbolCompletionKind::ConstantType === $context->kind ? $index->constantTypeNames() : $index->enumNames();
            $leadingSlash = str_starts_with($context->prefix, '\\');
            $prefix = ltrim($context->prefix, '\\');
            $items = [];
            foreach ($names as $name) {
                if (!str_starts_with($name, $prefix)) {
                    continue;
                }
                $declaration = $index->typeDeclarations($name)[0] ?? null;
                if (null === $declaration) {
                    continue;
                }
                $inserted = str_replace('\\', '\\\\', ($leadingSlash ? '\\' : '').$name);
                $items[] = [
                    'label' => $name,
                    'kind' => $this->completionItemKind($declaration->kind),
                    'detail' => 'PHP '.$declaration->kind->value,
                    'filterText' => $inserted,
                    'textEdit' => $this->protocol->textEdit($context->range, $inserted),
                ];
            }

            return $items;
        }
        $className = $context->className;
        if (null === $className) {
            return null;
        }
        $declarations = $index->completableMembers($className, TwigPhpSymbolCompletionKind::EnumCase === $context->kind);
        $items = [];
        foreach ($declarations as $declaration) {
            $name = $declaration->memberName;
            if (null === $name || !str_starts_with($name, $context->prefix)) {
                continue;
            }
            $items[] = [
                'label' => $name,
                'kind' => $this->completionItemKind($declaration->kind),
                'detail' => 'PHP '.$declaration->kind->value,
                'textEdit' => $this->protocol->textEdit($context->range, $name),
            ];
        }

        return $items;
    }

    public function hover(array $params): ?array
    {
        $request = $this->requests->positioned($params);
        if (null === $request) {
            return null;
        }

        $resolved = $this->resolveTwig($request);
        if (null === $resolved) {
            return null;
        }
        [$reference, $declarations] = $resolved;
        $declaration = $declarations[0];
        $symbol = $reference->className;
        if (null !== $reference->memberName) {
            $symbol .= '::'.$reference->memberName;
        }
        $value = \sprintf('PHP %s: `%s`', $declaration->kind->value, $this->markdownCode($symbol));
        if ('' !== $declaration->signature) {
            $value .= "\n\n```php\n".$declaration->signature."\n```";
        }
        if (null !== $declaration->description) {
            $value .= "\n\n".$declaration->description;
        }

        return $this->protocol->markdownHover($value);
    }

    public function definition(PositionedRequest $request): array
    {
        $resolved = $this->resolveTwig($request);
        if (null === $resolved) {
            return [];
        }
        [, $declarations] = $resolved;

        return array_map(
            fn (TwigPhpSymbolDeclaration $declaration): array => $this->protocol->location($declaration->uri, $declaration->range),
            $declarations,
        );
    }

    public function references(ReferencesRequest $request): array
    {
        if (!\in_array($request->document->languageId, ['php', 'twig'], true)) {
            return [];
        }
        $index = $this->indexes->forProject($request->project);
        if ('php' === $request->document->languageId) {
            $declaration = $index->declarationAt($request->document->uri, $request->position);
            if (null === $declaration) {
                return [];
            }
            $className = $declaration->className;
            $memberName = $declaration->memberName;
            $declarations = [$declaration];
        } else {
            $text = $request->document->text;
            $reference = $this->extractor->referenceAt($request->document->uri, $text, $request->offset);
            if (null === $reference) {
                return [];
            }
            $className = $reference->className;
            $memberName = $reference->memberName;
            $declarations = $this->declarations($index, $className, $memberName);
            if ([] === $declarations) {
                return [];
            }
        }

        return $this->protocol->locations([
            ...$index->references($className, $memberName),
            ...($request->includeDeclaration ? $declarations : []),
        ]);
    }

    /** @return array{TwigPhpSymbolReference, list<TwigPhpSymbolDeclaration>}|null */
    private function resolveTwig(PositionedRequest $request): ?array
    {
        if ('twig' !== $request->document->languageId) {
            return null;
        }
        $text = $request->document->text;
        $reference = $this->extractor->referenceAt($request->document->uri, $text, $request->offset);
        if (null === $reference) {
            return null;
        }
        $declarations = $this->declarations($this->indexes->forProject($request->project), $reference->className, $reference->memberName);

        return [] === $declarations ? null : [$reference, $declarations];
    }

    /** @return list<TwigPhpSymbolDeclaration> */
    private function declarations(TwigPhpSymbolSourceIndex $index, string $className, ?string $memberName): array
    {
        return null === $memberName ? $index->typeDeclarations($className) : $index->memberDeclarations($className, $memberName);
    }

    private function completionItemKind(TwigPhpSymbolKind $kind): int
    {
        return match ($kind) {
            TwigPhpSymbolKind::Class_ => 7,
            TwigPhpSymbolKind::Interface_ => 8,
            TwigPhpSymbolKind::Enum => 13,
            TwigPhpSymbolKind::EnumCase => 20,
            TwigPhpSymbolKind::ClassConstant => 21,
        };
    }

    private function markdownCode(string $value): string
    {
        return str_replace('`', '\\`', $value);
    }
}
