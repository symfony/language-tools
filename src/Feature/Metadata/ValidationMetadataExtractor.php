<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpTypeDeclaration;

final class ValidationMetadataExtractor
{
    private const CONSTRAINT_NAMESPACE = 'Symfony\\Component\\Validator\\Constraints\\';

    public function __construct(
        private readonly PositionConverter $converter,
    ) {
    }

    /** @return list<MetadataSourceSymbol> */
    public function declarationSymbols(string $uri, PhpTypeDeclaration $type, Range $range): array
    {
        if ('Symfony\\Component\\Validator\\Constraint' !== $type->parentClassName) {
            return [];
        }
        $separator = strrpos($type->name, '\\');

        return [new MetadataSourceSymbol(
            MetadataSymbolKind::Constraint,
            false === $separator ? $type->name : substr($type->name, $separator + 1),
            $uri,
            $range,
            true,
        )];
    }

    /** @return list<MetadataSourceSymbol> */
    public function referenceSymbols(string $uri, string $text, PhpDocument $php): array
    {
        $symbols = [];
        foreach ($php->attributes as $attribute) {
            $className = $attribute->name;
            $rawName = substr($text, $attribute->nameStartOffset, $attribute->nameEndOffset - $attribute->nameStartOffset);
            if (str_starts_with($className, self::CONSTRAINT_NAMESPACE)) {
                $name = substr($className, \strlen(self::CONSTRAINT_NAMESPACE));
                if (str_contains($name, '\\')) {
                    continue;
                }
                $segmentOffset = (int) strrpos('\\'.$rawName, '\\');
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::Constraint,
                    $name,
                    $uri,
                    $this->converter->toRange($text, $attribute->nameStartOffset + $segmentOffset, \strlen($rawName) - $segmentOffset),
                    false,
                );

                continue;
            }
            $aliasOffset = '\\' === ($rawName[0] ?? '') ? 1 : 0;
            $separator = strpos($rawName, '\\', $aliasOffset);
            $alias = substr($rawName, $aliasOffset, false === $separator ? null : $separator - $aliasOffset);
            $imported = $php->imports()[$alias] ?? null;
            if (!\is_string($imported)
                || (!str_contains($imported, '\\Validator\\') && !str_contains($imported, '\\Constraints\\'))
            ) {
                continue;
            }
            $symbols[] = new MetadataSourceSymbol(
                MetadataSymbolKind::Constraint,
                $alias,
                $uri,
                $this->converter->toRange($text, $attribute->nameStartOffset + $aliasOffset, \strlen($alias)),
                false,
            );
        }

        return $symbols;
    }

    /** @return list<array{constraint: string, option: string, range: Range}> */
    public function options(string $text, PhpDocument $php): array
    {
        $options = [];
        foreach ($php->attributes as $attribute) {
            foreach ($attribute->arguments as $argument) {
                $name = $argument->name;
                $start = $argument->nameStartOffset;
                $end = $argument->nameEndOffset;
                if (null === $name || !\is_int($start) || !\is_int($end)) {
                    continue;
                }
                $options[] = ['constraint' => $attribute->name, 'option' => $name, 'range' => $this->converter->toRange($text, $start, $end - $start)];
            }
        }

        return $options;
    }

    public function completionContext(string $text, string $source, PhpDocument $php, int $offset): ?MetadataCompletionContext
    {
        $before = substr($source, 0, $offset);
        $attribute = strrpos($before, '#[');
        if (false === $attribute || str_contains(substr($before, $attribute), ']')) {
            return null;
        }
        $expression = substr($before, $attribute + 2);
        if (preg_match('/^\s*([\\\\A-Za-z_][A-Za-z0-9_\\\\]*)\s*\((.*)$/s', $expression, $constraint) && preg_match('/(?:^|,)\s*([A-Za-z_][A-Za-z0-9_]*)$/', $constraint[2], $option, \PREG_OFFSET_CAPTURE)) {
            $optionOffset = $attribute + 2 + strpos($expression, $constraint[2]) + $option[1][1];

            return $this->context(MetadataCompletionKind::ConstraintOption, $option[1][0], $text, $optionOffset, $php->resolveName($constraint[1]));
        }
        if (!preg_match('/^\s*([\\\\A-Za-z_][A-Za-z0-9_\\\\]*)$/', $expression, $constraint, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $name = $constraint[1][0];
        $separator = strrpos($name, '\\');
        if (false !== $separator) {
            $class = $php->resolveName(substr($name, 0, $separator + 1).'Constraint');
            $name = substr($name, $separator + 1);
            if (str_starts_with($class, self::CONSTRAINT_NAMESPACE)) {
                $nameOffset = $attribute + 2 + $constraint[1][1] + $separator + 1;

                return $this->context(MetadataCompletionKind::Constraint, $name, $text, $nameOffset);
            }

            return null;
        }
        $candidates = [];
        foreach ($php->imports() as $alias => $class) {
            if (str_starts_with($alias, $name)
                && str_starts_with($class, self::CONSTRAINT_NAMESPACE)
                && !str_contains(substr($class, \strlen(self::CONSTRAINT_NAMESPACE)), '\\')
            ) {
                $candidates[] = ['label' => $alias, 'class' => $class];
            }
        }
        if ([] === $candidates) {
            return null;
        }

        return $this->context(MetadataCompletionKind::Constraint, $name, $text, $attribute + 2 + $constraint[1][1], candidates: $candidates);
    }

    /** @param list<array{label: string, class: string}> $candidates */
    private function context(MetadataCompletionKind $kind, string $prefix, string $text, int $offset, ?string $owner = null, array $candidates = []): MetadataCompletionContext
    {
        return new MetadataCompletionContext($kind, $prefix, $this->converter->toRange($text, $offset, \strlen($prefix)), $owner, $candidates);
    }
}
