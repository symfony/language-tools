<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\SavedDocumentMatcher;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class ConfigurationDiagnosticProvider implements DiagnosticProviderInterface
{
    private const PROVISIONAL_CODES = ['config.invalid_type', 'config.unknown_key', 'env.incompatible_type'];

    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly ProjectPathResolver $projectPaths,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly ConfigurationIndexRegistry $indexes,
        private readonly RouteIndexRegistry $routeIndexes,
        private readonly ConfigurationValidationRegistry $validations,
        private readonly SavedDocumentMatcher $savedDocuments,
        private readonly RuntimeConfiguration $runtimeConfiguration,
        private readonly ConfigurationPathResolver $paths,
        private readonly YamlConfigurationParser $yaml,
        private readonly ConfigurationValueValidator $values,
        private readonly PhpCommentParserInterface $phpComments,
        private readonly XmlCommentParser $xmlComments,
    ) {
    }

    public function name(): string
    {
        return 'configuration';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request || !\in_array($request->document->languageId, ['php', 'xml', 'yaml'], true)) {
            return null;
        }
        // bundle-internal fixtures target other kernels, so only the
        // application's own configuration is validated against its trees
        $relativePath = $this->projectPaths->relative($request->project, $request->document->uri);
        if (null === $relativePath
            || !str_starts_with($relativePath, 'config/')
            || $this->routeIndexes->forProject($request->project)->isResource($relativePath)
        ) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $diagnostics = match ($request->document->languageId) {
            'php' => $this->diagnosePhp($request->document, $index),
            'xml' => $this->diagnoseXml($request->document, $index),
            default => $this->diagnoseYaml($request->document, $request->project, $index),
        };

        return $this->applyValidation($request->document, $request->project, $relativePath, $diagnostics);
    }

    /** @return list<array<array-key, mixed>> */
    private function diagnoseYaml(Document $document, Project $project, ConfigurationIndex $index): array
    {
        $environmentScope = 'when@'.$this->runtimeConfiguration->environment($project);
        $occurrences = $this->yaml->parse($document->text, $index);
        $diagnostics = [];
        $seen = [];
        foreach ($occurrences as $occurrence) {
            if (!\in_array($occurrence->scope(), ['base', $environmentScope], true)) {
                continue;
            }
            $path = $occurrence->path();
            $root = $path[0] ?? null;
            if (null === $root || \in_array($root, ['parameters', 'services'], true) || !isset($index->roots()[$root])) {
                continue;
            }
            $key = implode('.', $path);
            $identity = $occurrence->scope().'|'.$key;
            if (isset($seen[$identity]) && !$occurrence->sequenceItem()) {
                $diagnostics[] = $this->diagnostic($occurrence->keyRange(), 1, 'config.duplicate_key', \sprintf('Configuration key "%s" is duplicated.', $key));
            }
            $seen[$identity] = true;
            $node = $index->find($path, $occurrence->sequenceDepths(), $occurrence->literalDepths());
            if (null === $node) {
                if (!$index->allowsUnknownKeys($path, $occurrence->sequenceDepths(), $occurrence->literalDepths())) {
                    $diagnostics[] = $this->diagnostic($occurrence->keyRange(), 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', $key));
                }
                continue;
            }
            if ($node->deprecated()) {
                $diagnostics[] = $this->diagnostic($occurrence->keyRange(), 2, 'config.deprecated_key', \sprintf('Configuration key "%s" is deprecated.', $key));
            }
            $environmentType = $this->values->environmentType($project, $occurrence->value());
            if (null !== $environmentType && !$this->values->acceptsType($node, $environmentType)) {
                $diagnostics[] = $this->diagnostic($occurrence->valueRange(), 1, 'env.incompatible_type', \sprintf('Environment expression returns %s, but "%s" expects %s.', $environmentType, $key, $node->type()));
            } elseif ('' !== $occurrence->value() && !$this->values->acceptsValue($node, $occurrence->value())) {
                $diagnostics[] = $this->diagnostic($occurrence->valueRange(), 1, 'config.invalid_type', \sprintf('Expected %s for "%s".', $node->type(), $key));
            }
        }
        preg_match_all('/^\t+\S.*$/m', $document->text, $tabbedLines, \PREG_OFFSET_CAPTURE);
        foreach ($tabbedLines[0] as [$line, $offset]) {
            $diagnostics[] = $this->diagnostic($this->converter->toRange($document->text, $offset, \strlen($line)), 1, 'config.malformed_structure', 'YAML indentation cannot contain tabs.');
        }

        return $diagnostics;
    }

    /**
     * @param list<array<array-key, mixed>> $diagnostics
     *
     * @return list<array<array-key, mixed>>
     */
    private function applyValidation(Document $document, Project $project, string $relativePath, array $diagnostics): array
    {
        $validation = $this->validations->result($project);
        $validatedSavedContent = $this->runtimeConfiguration->runtimeIndexing($project)
            && $validation->environment === $this->runtimeConfiguration->environment($project)
            && $this->savedDocuments->matches($project, $document);
        if (!$validatedSavedContent || ConfigurationValidationResult::INVALID !== $validation->state) {
            return $this->provisional($diagnostics);
        }

        $diagnostics = $this->provisional($diagnostics, true);
        $vendorDiagnostic = $this->vendorDiagnostic($document, $relativePath, $validation);
        if (null === $vendorDiagnostic) {
            return $diagnostics;
        }

        return [$vendorDiagnostic['diagnostic'], ...array_values(array_filter(
            $diagnostics,
            function (array $diagnostic) use ($vendorDiagnostic): bool {
                $range = $diagnostic['range'] ?? null;

                return !\is_array($range)
                    || !$this->protocol->sameRange($vendorDiagnostic['range'], $range)
                    || $vendorDiagnostic['diagnostic']['code'] !== ($diagnostic['code'] ?? null);
            },
        ))];
    }

    /**
     * @param list<array<array-key, mixed>> $diagnostics
     *
     * @return list<array<array-key, mixed>>
     */
    private function provisional(array $diagnostics, bool $allErrors = false): array
    {
        foreach ($diagnostics as &$diagnostic) {
            if (($allErrors && 1 === ($diagnostic['severity'] ?? null))
                || \in_array($diagnostic['code'] ?? null, self::PROVISIONAL_CODES, true)
            ) {
                $diagnostic['severity'] = 2;
            }
        }
        unset($diagnostic);

        return $diagnostics;
    }

    /** @return array{diagnostic: array<array-key, mixed>, range: Range}|null */
    private function vendorDiagnostic(Document $document, string $relativePath, ConfigurationValidationResult $validation): ?array
    {
        if ('yaml' !== $validation->kind || $validation->file !== $relativePath) {
            return null;
        }
        $range = $this->validationRange($document, $validation);

        return ['diagnostic' => $this->diagnostic($range, 1, 'config.malformed_structure', 'The YAML configuration is invalid.'), 'range' => $range];
    }

    private function validationRange(Document $document, ConfigurationValidationResult $validation): Range
    {
        if (null === $validation->line) {
            return new Range(new Position(0, 0), new Position(0, 0));
        }
        $lines = explode("\n", $document->text);
        $line = min(max(0, $validation->line - 1), max(0, \count($lines) - 1));
        $start = 0;
        for ($index = 0; $index < $line; ++$index) {
            $start += \strlen($lines[$index]) + 1;
        }
        $end = $start + \strlen($lines[$line] ?? '');

        return new Range($this->converter->toPosition($document->text, $start), $this->converter->toPosition($document->text, $end));
    }

    /** @return list<array<array-key, mixed>> */
    private function diagnosePhp(Document $document, ConfigurationIndex $index): array
    {
        $diagnostics = [];
        $text = $this->phpComments->mask($document->text);
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)((?:->[A-Za-z_][A-Za-z0-9_]*\([^)]*\))+)/', $text, $chains, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($chains as $chain) {
            $path = [$this->paths->phpRoot(substr($text, 0, $chain[1][1]), $chain[1][0])];
            if (!isset($index->roots()[$path[0]])) {
                continue;
            }
            preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\(([^)]*)\)/', $chain[2][0], $methods, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($methods as $method) {
                $path[] = $this->paths->phpMethodName($method[1][0]);
                $node = $index->find($path);
                $offset = $chain[2][1] + $method[1][1];
                $range = $this->converter->toRange($document->text, $offset, \strlen($method[1][0]));
                if (null === $node) {
                    if (!$index->allowsUnknownKeys($path)) {
                        $diagnostics[] = $this->diagnostic($range, 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', implode('.', $path)));
                    }
                    break;
                }
                if ($node->deprecated()) {
                    $diagnostics[] = $this->diagnostic($range, 2, 'config.deprecated_key', \sprintf('Configuration key "%s" is deprecated.', implode('.', $path)));
                }
                $argument = trim($method[2][0]);
                if ('' !== $argument && !$this->values->acceptsValue($node, $argument)) {
                    $diagnostics[] = $this->diagnostic($range, 1, 'config.invalid_type', \sprintf('Expected %s for "%s".', $node->type(), implode('.', $path)));
                }
            }
        }

        return $diagnostics;
    }

    /** @return list<array<array-key, mixed>> */
    private function diagnoseXml(Document $document, ConfigurationIndex $index): array
    {
        $diagnostics = [];
        $stack = [];
        $elements = [];
        $text = $this->xmlComments->mask($document->text);
        preg_match_all('/<\s*(\/)?\s*([A-Za-z_][A-Za-z0-9_.-]*(?::[A-Za-z_][A-Za-z0-9_.-]*)?)([^>]*)>/', $text, $tags, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($tags as $tag) {
            if ('' !== $tag[1][0]) {
                $open = array_pop($elements);
                if ($open !== $tag[2][0]) {
                    $diagnostics[] = $this->diagnostic($this->converter->toRange($document->text, $tag[2][1], \strlen($tag[2][0])), 1, 'config.malformed_structure', \sprintf('Closing element "%s" does not match "%s".', $tag[2][0], $open ?? 'none'));
                }
                if ([] !== $stack) {
                    array_pop($stack);
                }
                continue;
            }
            if (!str_ends_with(rtrim($tag[0][0]), '/>')) {
                $elements[] = $tag[2][0];
            }
            $name = $tag[2][0];
            $path = $this->paths->xmlElementPath($stack, $name, $index);
            if (null === $path) {
                continue;
            }
            $node = $index->find($path);
            $nameRange = $this->converter->toRange($document->text, $tag[2][1], \strlen($name));
            if (null === $node) {
                if (!$index->allowsUnknownKeys($path)) {
                    $diagnostics[] = $this->diagnostic($nameRange, 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', implode('.', $path)));
                }
            } elseif ($node->deprecated()) {
                $diagnostics[] = $this->diagnostic($nameRange, 2, 'config.deprecated_key', \sprintf('Configuration key "%s" is deprecated.', implode('.', $path)));
            }
            if (null !== $node) {
                preg_match_all('/([A-Za-z_][A-Za-z0-9_.-]*)\s*=\s*(["\'])(.*?)\2/', $tag[3][0], $attributes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
                foreach ($attributes as $attribute) {
                    $attributeName = str_replace('-', '_', $attribute[1][0]);
                    $child = $node->child($attributeName);
                    $attributePath = [...$path, $attributeName];
                    $range = $this->converter->toRange($document->text, $tag[3][1] + $attribute[1][1], \strlen($attribute[1][0]));
                    if (null === $child) {
                        if (!$index->allowsUnknownKeys($attributePath)) {
                            $diagnostics[] = $this->diagnostic($range, 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', implode('.', $attributePath)));
                        }
                    } elseif (!$this->values->acceptsValue($child, $attribute[3][0])) {
                        $diagnostics[] = $this->diagnostic($range, 1, 'config.invalid_type', \sprintf('Expected %s for "%s".', $child->type(), implode('.', $attributePath)));
                    }
                }
            }
            if (!str_ends_with(rtrim($tag[0][0]), '/>')) {
                $stack = $path;
            }
        }
        if ([] !== $elements) {
            $diagnostics[] = $this->diagnostic($this->converter->toRange($document->text, \strlen($document->text), 0), 1, 'config.malformed_structure', \sprintf('Element "%s" is not closed.', array_pop($elements)));
        }

        return $diagnostics;
    }

    /** @return array{range: array<string, array<string, int>>, severity: int, source: string, code: string, message: string} */
    private function diagnostic(Range $range, int $severity, string $code, string $message): array
    {
        return $this->protocol->diagnostic($range, $severity, $code, $message);
    }
}
