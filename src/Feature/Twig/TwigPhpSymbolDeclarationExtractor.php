<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Php\PhpConstantKind;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpTypeKind;

final class TwigPhpSymbolDeclarationExtractor
{
    public function __construct(private readonly PositionConverter $converter)
    {
    }

    /** @return list<TwigPhpSymbolDeclaration> */
    public function extract(string $uri, string $text, PhpDocument $document): array
    {
        $typeKinds = [];
        foreach ($document->typeDeclarations as $type) {
            $typeKinds[strtolower(ltrim($type->name, '\\'))] = $type->kind;
        }

        $constants = [];
        foreach ($document->constantDeclarations as $constant) {
            if (PhpTypeKind::Trait_ !== ($typeKinds[strtolower(ltrim($constant->className, '\\'))] ?? null)) {
                $constants[] = $constant;
            }
        }

        $constantOwners = [];
        foreach ($constants as $constant) {
            $constantOwners[strtolower(ltrim($constant->className, '\\'))] = true;
        }

        $declarations = [];
        foreach ($document->typeDeclarations as $type) {
            if (!$type->isEnum() && !isset($constantOwners[strtolower(ltrim($type->name, '\\'))])) {
                continue;
            }
            $kind = match ($type->kind) {
                PhpTypeKind::Class_ => TwigPhpSymbolKind::Class_,
                PhpTypeKind::Interface_ => TwigPhpSymbolKind::Interface_,
                PhpTypeKind::Trait_ => TwigPhpSymbolKind::Trait_,
                PhpTypeKind::Enum => TwigPhpSymbolKind::Enum,
            };
            $declarations[] = new TwigPhpSymbolDeclaration(
                $kind,
                $type->name,
                null,
                $uri,
                $this->converter->toRange($text, $type->nameStartOffset, $type->nameEndOffset - $type->nameStartOffset),
                $type->signature,
                $type->description,
                true,
            );
        }
        foreach ($constants as $constant) {
            $declarations[] = new TwigPhpSymbolDeclaration(
                PhpConstantKind::ClassConstant === $constant->kind ? TwigPhpSymbolKind::ClassConstant : TwigPhpSymbolKind::EnumCase,
                $constant->className,
                $constant->name,
                $uri,
                $this->converter->toRange($text, $constant->nameStartOffset, $constant->nameEndOffset - $constant->nameStartOffset),
                $constant->signature,
                $constant->description,
                $constant->public,
            );
        }

        return $declarations;
    }
}
