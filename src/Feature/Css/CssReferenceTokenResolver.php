<?php

namespace Symfony\Lsp\Feature\Css;

use Symfony\Lsp\Document\ProjectDocumentReader;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Project\Project;

/**
 * Reads the resolved token values that symfony/ux-css dumps in the TOKENS
 * constant of its CssReference class, by category.
 */
final class CssReferenceTokenResolver
{
    public const CLASS_NAME = 'Symfony\UX\Css\CssReference';

    /** @var array<string, array<string, array<string, string>>> */
    private array $tokens = [];

    public function __construct(
        private readonly DependencyInjectionSourceIndexRegistry $classIndexes,
        private readonly ProjectDocumentReader $reader,
        private readonly PhpParserInterface $parser,
    ) {
    }

    /** @return array<string, array<string, string>> */
    public function tokens(Project $project): array
    {
        foreach ($this->classIndexes->forProject($project)->classDeclarations(self::CLASS_NAME) as $class) {
            $source = $this->reader->read($project, $class->uri)?->text;
            if (null === $source) {
                continue;
            }
            $key = $class->uri."\0".hash('xxh128', $source);
            if (!isset($this->tokens[$key])) {
                $this->tokens = [$key => $this->extract($this->parser->parse($source), self::CLASS_NAME)];
            }

            return $this->tokens[$key];
        }

        return [];
    }

    /** @return array<string, array<string, string>> */
    public function extract(PhpDocument $document, string $className): array
    {
        if (0 !== strcasecmp(self::CLASS_NAME, $className)) {
            return [];
        }
        $tokens = array_find(
            $document->constantDeclarations,
            static fn ($constant): bool => 'TOKENS' === $constant->name && 0 === strcasecmp($constant->className, $className),
        )?->arrayValue;
        $categories = [];
        foreach ($tokens->entries ?? [] as $category) {
            if (null === $category->key || null === $category->arrayValue) {
                continue;
            }
            $values = [];
            foreach ($category->arrayValue->entries as $token) {
                $value = $token->value?->scalarValue;
                if (null !== $token->key && (\is_string($value) || \is_int($value) || \is_float($value))) {
                    $values[$token->key->value] = (string) $value;
                }
            }
            $categories[$category->key->value] = $values;
        }

        return $categories;
    }
}
