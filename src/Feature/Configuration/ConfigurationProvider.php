<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Feature\DocumentLinkProviderInterface;
use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class ConfigurationProvider implements CompletionProviderInterface, DiagnosticProviderInterface, DocumentLinkProviderInterface, HoverProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly PositionConverter $converter,
        private readonly ConfigurationIndexRegistry $indexes,
        private readonly YamlConfigurationParser $yaml,
        private readonly EnvironmentIndexRegistry $environmentIndexes,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;

        return match ($document->languageId()) {
            'yaml' => $this->completeYaml($document, $project, $position),
            'php' => $this->completePhp($document, $project, $position),
            'xml' => $this->completeXml($document, $project, $position),
            default => null,
        };
    }

    public function hover(array $params): ?array
    {
        $request = $this->resolver->resolve($params);
        if (null === $request) {
            return null;
        }
        [$document, $project, $position] = $request;
        $offset = $this->converter->toByteOffset($document->text(), $position);
        $index = $this->indexes->forProject($project);
        if ('php' === $document->languageId()) {
            $resolved = $this->resolvePhpNode($document, $index, $offset);

            return null === $resolved ? null : ['contents' => ['kind' => 'markdown', 'value' => $this->description($resolved[0], $resolved[1])]];
        }
        if ('xml' === $document->languageId()) {
            $resolved = $this->resolveXmlNode($document, $index, $offset);

            return null === $resolved ? null : ['contents' => ['kind' => 'markdown', 'value' => $this->description($resolved[0], $resolved[1])]];
        }
        if ('yaml' !== $document->languageId()) {
            return null;
        }
        foreach ($this->yaml->parse($document->text()) as $occurrence) {
            if (!$this->contains($document->text(), $occurrence->keyRange(), $offset)) {
                continue;
            }
            $node = $index->find($occurrence->path());

            return null === $node ? null : ['contents' => ['kind' => 'markdown', 'value' => $this->description($occurrence->path(), $node)]];
        }

        return null;
    }

    public function diagnostics(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }
        $document = $this->documents->get($textDocument['uri']);
        if (null === $document || !\in_array($document->languageId(), ['php', 'xml', 'yaml'], true)) {
            return null;
        }
        $project = $this->projects->forDocumentUri($document->uri());
        if (null === $project) {
            return null;
        }
        $index = $this->indexes->forProject($project);
        if ('php' === $document->languageId()) {
            return $this->diagnosePhp($document, $index);
        }
        if ('xml' === $document->languageId()) {
            return $this->diagnoseXml($document, $index);
        }
        $occurrences = $this->yaml->parse($document->text());
        $diagnostics = [];
        $seen = [];
        foreach ($occurrences as $occurrence) {
            $path = $occurrence->path();
            $root = $path[0] ?? null;
            if (null === $root || !isset($index->roots()[$root])) {
                continue;
            }
            $key = implode('.', $path);
            $identity = $occurrence->scope().'|'.$key;
            if (isset($seen[$identity]) && !$occurrence->sequenceItem()) {
                $diagnostics[] = $this->diagnostic($occurrence->keyRange(), 1, 'config.duplicate_key', \sprintf('Configuration key "%s" is duplicated.', $key));
            }
            $seen[$identity] = true;
            $node = $index->find($path);
            if (null === $node) {
                $diagnostics[] = $this->diagnostic($occurrence->keyRange(), 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', $key));
                continue;
            }
            if ($node->deprecated()) {
                $diagnostics[] = $this->diagnostic($occurrence->keyRange(), 2, 'config.deprecated_key', \sprintf('Configuration key "%s" is deprecated.', $key));
            }
            $environmentType = $this->environmentType($project, $occurrence->value());
            if (null !== $environmentType && !$this->compatibleType($node->type(), $environmentType)) {
                $diagnostics[] = $this->diagnostic($occurrence->valueRange(), 1, 'env.incompatible_type', \sprintf('Environment expression returns %s, but "%s" expects %s.', $environmentType, $key, $node->type()));
            } elseif ('' !== $occurrence->value() && !$this->validValue($node, $occurrence->value())) {
                $diagnostics[] = $this->diagnostic($occurrence->valueRange(), 1, 'config.invalid_type', \sprintf('Expected %s for "%s".', $node->type(), $key));
            }
        }
        preg_match_all('/^\t+\S.*$/m', $document->text(), $tabbedLines, \PREG_OFFSET_CAPTURE);
        foreach ($tabbedLines[0] as [$line, $offset]) {
            $diagnostics[] = $this->diagnostic($this->offsetRange($document->text(), $offset, \strlen($line)), 1, 'config.malformed_structure', 'YAML indentation cannot contain tabs.');
        }
        foreach ($occurrences as $occurrence) {
            $node = $index->find($occurrence->path());
            if (null === $node || '' !== $occurrence->value()) {
                continue;
            }
            foreach ($node->children() as $child) {
                if (!$child->required() || $child->hasDefault()) {
                    continue;
                }
                $childKey = implode('.', [...$occurrence->path(), $child->name()]);
                if (!isset($seen[$occurrence->scope().'|'.$childKey])) {
                    $diagnostics[] = $this->diagnostic($occurrence->keyRange(), 1, 'config.missing_required_key', \sprintf('Required configuration key "%s" is missing.', $childKey));
                }
            }
        }

        return $diagnostics;
    }

    public function links(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }
        $document = $this->documents->get($textDocument['uri']);
        if (null === $document || 'yaml' !== $document->languageId()) {
            return null;
        }
        $links = [];
        preg_match_all('/\bresource\s*:\s*(["\']?)([^"\'\s#]+)\1/', $document->text(), $matches, \PREG_OFFSET_CAPTURE);
        $basePath = \dirname(rawurldecode((string) parse_url($document->uri(), \PHP_URL_PATH)));
        foreach ($matches[2] as [$resource, $offset]) {
            if (str_contains($resource, '*') || str_starts_with($resource, '@')) {
                continue;
            }
            $path = str_starts_with($resource, '/') ? $resource : $basePath.'/'.$resource;
            $path = $this->normalizePath($path);
            $links[] = ['range' => $this->range(new Range($this->converter->toPosition($document->text(), $offset), $this->converter->toPosition($document->text(), $offset + \strlen($resource)))), 'target' => 'file://'.str_replace('%2F', '/', rawurlencode($path))];
        }

        return $links;
    }

    /** @return list<array<array-key, mixed>>|null */
    private function completeYaml(Document $document, Project $project, Position $position): ?array
    {
        $offset = $this->converter->toByteOffset($document->text(), $position);
        $before = substr($document->text(), 0, $offset);
        $lineStart = strrpos($before, "\n");
        $lineStart = false === $lineStart ? 0 : $lineStart + 1;
        $line = substr($before, $lineStart);
        $index = $this->indexes->forProject($project);
        foreach ($this->yaml->parse($document->text()) as $occurrence) {
            if ($this->contains($document->text(), $occurrence->valueRange(), $offset)) {
                $node = $index->find($occurrence->path());
                if (null !== $node && [] !== $node->allowedValues()) {
                    $prefix = trim(substr($document->text(), $this->converter->toByteOffset($document->text(), $occurrence->valueRange()->start()), $offset));

                    return array_map(fn ($value): array => $this->completion((string) $value, (string) $value, 'Allowed value', $document->text(), $offset - \strlen($prefix), $position), $node->allowedValues());
                }
            }
        }
        if (!preg_match('/^(\s*)(?:-\s+)?([A-Za-z_][A-Za-z0-9_.-]*)?$/', $line, $match)) {
            return null;
        }
        $indent = \strlen($match[1]);
        $prefix = $match[2] ?? '';
        $parent = [];
        $previous = array_reverse($this->yaml->parse(substr($document->text(), 0, $lineStart)));
        foreach ($previous as $occurrence) {
            if ($occurrence->keyRange()->start()->character() < $indent) {
                $parent = $occurrence->path();
                break;
            }
        }
        $nodes = [] === $parent ? array_values($index->roots()) : $this->completionChildren($index->find($parent));
        $items = [];
        foreach ($nodes as $node) {
            if (str_starts_with($node->name(), $prefix)) {
                $items[] = $this->completion($node->name(), $node->name().':'.$this->yamlSnippet($node), $this->shortDescription($node), $document->text(), $offset - \strlen($prefix), $position);
            }
        }

        return $items;
    }

    /** @return list<array<array-key, mixed>>|null */
    private function completePhp(Document $document, Project $project, Position $position): ?array
    {
        $offset = $this->converter->toByteOffset($document->text(), $position);
        $before = substr($document->text(), 0, $offset);
        if (!preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)((?:->[A-Za-z_][A-Za-z0-9_]*\(\))*)->([A-Za-z_][A-Za-z0-9_]*)?$/', $before, $match)) {
            return null;
        }
        $path = [$this->phpRoot($before, $match[1])];
        preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\(\)/', $match[2], $methods);
        foreach ($methods[1] as $method) {
            $path[] = $this->snake($method);
        }
        $prefix = $match[3] ?? '';
        $parent = $this->indexes->forProject($project)->find($path);
        if (null === $parent) {
            return null;
        }
        $items = [];
        foreach ($this->completionChildren($parent) as $node) {
            $method = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $node->name()))));
            if (str_starts_with($method, $prefix)) {
                $items[] = $this->completion($method, $method.'('.$this->phpSnippet($node).')', $this->shortDescription($node), $document->text(), $offset - \strlen($prefix), $position);
            }
        }

        return $items;
    }

    /** @return list<array<array-key, mixed>>|null */
    private function completeXml(Document $document, Project $project, Position $position): ?array
    {
        $offset = $this->converter->toByteOffset($document->text(), $position);
        $before = substr($document->text(), 0, $offset);
        $index = $this->indexes->forProject($project);
        if (preg_match('/<(?<element>[A-Za-z_][A-Za-z0-9_.-]*(?::[A-Za-z_][A-Za-z0-9_.-]*)?)\b[^<>]*\s+(?<prefix>[A-Za-z_][A-Za-z0-9_.-]*)?$/', $before, $attributeMatch, \PREG_OFFSET_CAPTURE)) {
            $tagOffset = strrpos($before, '<');
            if (false !== $tagOffset) {
                $parentPath = $this->xmlPath(substr($before, 0, $tagOffset), $index);
                $path = $this->xmlElementPath($parentPath, $attributeMatch['element'][0], $index);
                $prefix = $attributeMatch['prefix'][0] ?? '';
                $items = [];
                foreach ($this->completionChildren(null === $path ? null : $index->find($path)) as $node) {
                    $xmlName = str_replace('_', '-', $node->name());
                    if (str_starts_with($xmlName, $prefix)) {
                        $items[] = $this->completion($xmlName, $xmlName.'="${1}"', $this->shortDescription($node), $document->text(), $offset - \strlen($prefix), $position);
                    }
                }

                return $items;
            }
        }
        if (!preg_match('/<(?:(?<alias>[A-Za-z_][A-Za-z0-9_.-]*):)?(?<prefix>[A-Za-z_][A-Za-z0-9_.-]*)?$/', $before, $match)) {
            return null;
        }
        $alias = $match['alias'] ?? '';
        $prefix = $match['prefix'] ?? '';
        $path = $this->xmlPath(substr($before, 0, -\strlen($match[0])), $index);
        if ('' !== $alias && [] === $path && isset($index->roots()[$alias])) {
            return str_starts_with('config', $prefix) ? [$this->completion('config', $alias.':config>', 'Bundle configuration root', $document->text(), $offset - \strlen($prefix), $position)] : [];
        }
        $nodes = [] === $path ? array_values($index->roots()) : $this->completionChildren($index->find($path));
        $items = [];
        foreach ($nodes as $node) {
            $xmlName = str_replace('_', '-', $node->name());
            if (str_starts_with($xmlName, $prefix)) {
                $newText = ('' !== $alias ? $alias.':' : '').$xmlName.'>';
                $items[] = $this->completion($xmlName, $newText, $this->shortDescription($node), $document->text(), $offset - \strlen($prefix), $position);
            }
        }

        return $items;
    }

    /** @return array{list<string>, ConfigurationNode}|null */
    private function resolvePhpNode(Document $document, ConfigurationIndex $index, int $cursor): ?array
    {
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)((?:->[A-Za-z_][A-Za-z0-9_]*\([^)]*\))+)/', $document->text(), $chains, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($chains as $chain) {
            $path = [$this->phpRoot(substr($document->text(), 0, $chain[1][1]), $chain[1][0])];
            preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\(([^)]*)\)/', $chain[2][0], $methods, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($methods as $method) {
                $path[] = $this->snake($method[1][0]);
                $start = $chain[2][1] + $method[1][1];
                if ($cursor >= $start && $cursor <= $start + \strlen($method[1][0])) {
                    $node = $index->find($path);

                    return null === $node ? null : [$path, $node];
                }
            }
        }

        return null;
    }

    /** @return array{list<string>, ConfigurationNode}|null */
    private function resolveXmlNode(Document $document, ConfigurationIndex $index, int $cursor): ?array
    {
        $stack = [];
        preg_match_all('/<\s*(\/)?\s*([A-Za-z_][A-Za-z0-9_.-]*(?::[A-Za-z_][A-Za-z0-9_.-]*)?)([^>]*)>/', $document->text(), $tags, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($tags as $tag) {
            if ('' !== $tag[1][0]) {
                if ([] !== $stack) {
                    array_pop($stack);
                }
                continue;
            }
            $path = $this->xmlElementPath($stack, $tag[2][0], $index);
            $start = $tag[2][1];
            if (null !== $path && $cursor >= $start && $cursor <= $start + \strlen($tag[2][0])) {
                $node = $index->find($path);

                return null === $node ? null : [$path, $node];
            }
            if (null !== $path && !str_ends_with(rtrim($tag[0][0]), '/>')) {
                $stack = $path;
            }
        }

        return null;
    }

    /** @return list<array<array-key, mixed>> */
    private function diagnosePhp(Document $document, ConfigurationIndex $index): array
    {
        $diagnostics = [];
        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)((?:->[A-Za-z_][A-Za-z0-9_]*\([^)]*\))+)/', $document->text(), $chains, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($chains as $chain) {
            $path = [$this->phpRoot(substr($document->text(), 0, $chain[1][1]), $chain[1][0])];
            if (!isset($index->roots()[$path[0]])) {
                continue;
            }
            preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\(([^)]*)\)/', $chain[2][0], $methods, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($methods as $method) {
                $path[] = $this->snake($method[1][0]);
                $node = $index->find($path);
                $offset = $chain[2][1] + $method[1][1];
                $range = $this->offsetRange($document->text(), $offset, \strlen($method[1][0]));
                if (null === $node) {
                    $diagnostics[] = $this->diagnostic($range, 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', implode('.', $path)));
                    break;
                }
                if ($node->deprecated()) {
                    $diagnostics[] = $this->diagnostic($range, 2, 'config.deprecated_key', \sprintf('Configuration key "%s" is deprecated.', implode('.', $path)));
                }
                $argument = trim($method[2][0]);
                if ('' !== $argument && !$this->validValue($node, $argument)) {
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
        preg_match_all('/<\s*(\/)?\s*([A-Za-z_][A-Za-z0-9_.-]*(?::[A-Za-z_][A-Za-z0-9_.-]*)?)([^>]*)>/', $document->text(), $tags, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($tags as $tag) {
            if ('' !== $tag[1][0]) {
                $open = array_pop($elements);
                if ($open !== $tag[2][0]) {
                    $diagnostics[] = $this->diagnostic($this->offsetRange($document->text(), $tag[2][1], \strlen($tag[2][0])), 1, 'config.malformed_structure', \sprintf('Closing element "%s" does not match "%s".', $tag[2][0], $open ?? 'none'));
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
            $path = $this->xmlElementPath($stack, $name, $index);
            if (null === $path) {
                continue;
            }
            $node = $index->find($path);
            $nameRange = $this->offsetRange($document->text(), $tag[2][1], \strlen($name));
            if (null === $node) {
                $diagnostics[] = $this->diagnostic($nameRange, 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', implode('.', $path)));
            } elseif ($node->deprecated()) {
                $diagnostics[] = $this->diagnostic($nameRange, 2, 'config.deprecated_key', \sprintf('Configuration key "%s" is deprecated.', implode('.', $path)));
            }
            if (null !== $node) {
                preg_match_all('/([A-Za-z_][A-Za-z0-9_.-]*)\s*=\s*(["\'])(.*?)\2/', $tag[3][0], $attributes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
                foreach ($attributes as $attribute) {
                    $attributeName = str_replace('-', '_', $attribute[1][0]);
                    $child = $node->child($attributeName);
                    $attributePath = [...$path, $attributeName];
                    $range = $this->offsetRange($document->text(), $tag[3][1] + $attribute[1][1], \strlen($attribute[1][0]));
                    if (null === $child) {
                        $diagnostics[] = $this->diagnostic($range, 1, 'config.unknown_key', \sprintf('Unknown configuration key "%s".', implode('.', $attributePath)));
                    } elseif (!$this->validValue($child, $attribute[3][0])) {
                        $diagnostics[] = $this->diagnostic($range, 1, 'config.invalid_type', \sprintf('Expected %s for "%s".', $child->type(), implode('.', $attributePath)));
                    }
                }
            }
            if (!str_ends_with(rtrim($tag[0][0]), '/>')) {
                $stack = $path;
            }
        }
        if ([] !== $elements) {
            $diagnostics[] = $this->diagnostic($this->offsetRange($document->text(), \strlen($document->text()), 0), 1, 'config.malformed_structure', \sprintf('Element "%s" is not closed.', array_pop($elements)));
        }

        return $diagnostics;
    }

    /** @return list<string> */
    private function xmlPath(string $text, ConfigurationIndex $index): array
    {
        $stack = [];
        preg_match_all('/<\s*(\/)?\s*([A-Za-z_][A-Za-z0-9_.-]*(?::[A-Za-z_][A-Za-z0-9_.-]*)?)[^>]*>/', $text, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ('' !== $match[1]) {
                if ([] !== $stack) {
                    array_pop($stack);
                }
                continue;
            }
            $path = $this->xmlElementPath($stack, $match[2], $index);
            if (null !== $path && !str_ends_with(rtrim($match[0]), '/>')) {
                $stack = $path;
            }
        }

        return $stack;
    }

    /**
     * @param list<string> $stack
     *
     * @return list<string>|null
     */
    private function xmlElementPath(array $stack, string $qualifiedName, ConfigurationIndex $index): ?array
    {
        if (str_contains($qualifiedName, ':')) {
            [$alias, $name] = explode(':', $qualifiedName, 2);
            $name = str_replace('-', '_', $name);
            if ('config' === $name && isset($index->roots()[$alias])) {
                return [$alias];
            }
            if (([] === $stack || $stack[0] === $alias) && isset($index->roots()[$alias])) {
                return [...([] === $stack ? [$alias] : $stack), $name];
            }

            return null;
        }
        $name = str_replace('-', '_', $qualifiedName);
        if ([] === $stack && !isset($index->roots()[$name])) {
            return null;
        }

        return [] === $stack ? [$name] : [...$stack, $name];
    }

    private function environmentType(Project $project, string $value): ?string
    {
        $value = trim($value, " \t\"'");
        if (1 !== preg_match('/^%env\(([^)]+)\)%$/', $value, $match)) {
            return null;
        }
        $separator = strpos($match[1], ':');
        if (false === $separator) {
            return 'string';
        }

        return $this->environmentIndexes->forProject($project)->processors()[substr($match[1], 0, $separator)] ?? null;
    }

    private function compatibleType(string $expected, string $actual): bool
    {
        $expected = 'boolean' === $expected ? 'bool' : $expected;
        if (!\in_array($expected, ['array', 'bool', 'float', 'integer'], true)) {
            return true;
        }
        $expected = 'integer' === $expected ? 'int' : $expected;
        $actualTypes = preg_split('/[|&]/', str_replace(['?', '"'], '', $actual), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return \in_array($expected, $actualTypes, true) || ('float' === $expected && \in_array('int', $actualTypes, true));
    }

    /** @return list<ConfigurationNode> */
    private function completionChildren(?ConfigurationNode $node): array
    {
        if (null === $node) {
            return [];
        }
        if ([] !== $node->children()) {
            return $node->children();
        }

        return $node->prototype()?->children() ?? [];
    }

    private function validValue(ConfigurationNode $node, string $value): bool
    {
        $plain = trim($value, " \t\"'");
        if (str_contains($plain, '%') || str_starts_with($plain, '$')) {
            return true;
        }
        if ([] !== $node->allowedValues() && !\in_array($plain, array_map('strval', $node->allowedValues()), true)) {
            return false;
        }

        return match ($node->type()) {
            'boolean' => \in_array(strtolower($plain), ['true', 'false', 'yes', 'no', '0', '1'], true),
            'integer' => 1 === preg_match('/^-?\d+$/', $plain),
            'float' => is_numeric($plain),
            'array' => '' === $plain || str_starts_with($plain, '[') || str_starts_with($plain, '{'),
            default => true,
        };
    }

    private function phpSnippet(ConfigurationNode $node): string
    {
        if ('array' === $node->type()) {
            return '';
        }
        if ('boolean' === $node->type()) {
            return '${1:true}';
        }
        if ([] !== $node->allowedValues()) {
            return "'".'${1:'.(string) $node->allowedValues()[0]."}'";
        }

        return '${1}';
    }

    private function yamlSnippet(ConfigurationNode $node): string
    {
        return match ($node->type()) {
            'boolean' => ' ${1:true}', 'integer', 'float', 'scalar', 'enum', 'variable' => ' ${1}', default => '',
        };
    }

    /** @param list<string> $path */
    private function description(array $path, ConfigurationNode $node): string
    {
        $lines = ['`'.implode('.', $path).'`', '', 'Type: `'.$node->type().'`'];
        if (null !== $node->info()) {
            $lines[] = '';
            $lines[] = $node->info();
        }
        if ($node->required()) {
            $lines[] = '';
            $lines[] = 'Required: yes';
        }
        if ($node->hasDefault()) {
            $lines[] = '';
            $lines[] = 'Default: '.$node->defaultSummary();
        }
        if ([] !== $node->allowedValues()) {
            $lines[] = '';
            $lines[] = 'Allowed values: `'.implode('`, `', array_map('strval', $node->allowedValues())).'`';
        }
        if (null !== $node->example()) {
            $lines[] = '';
            $lines[] = 'Example: `'.json_encode($node->example(), \JSON_UNESCAPED_SLASHES).'`';
        }
        if ($node->deprecated()) {
            $lines[] = '';
            $lines[] = '**Deprecated**';
        }

        return implode("\n", $lines);
    }

    private function shortDescription(ConfigurationNode $node): string
    {
        return $node->type().(null !== $node->info() ? ' - '.$node->info() : '');
    }

    private function phpRoot(string $before, string $variable): string
    {
        preg_match_all('/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*Config)\s+\$'.preg_quote($variable, '/').'\b/', $before, $matches);
        $class = end($matches[1]);
        if (false === $class) {
            return $this->snake($variable);
        }
        $shortName = substr($class, (int) strrpos('\\'.$class, '\\'));

        return $this->snake(substr($shortName, 0, -\strlen('Config')));
    }

    private function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    private function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }
            if ('..' === $part) {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return '/'.implode('/', $parts);
    }

    private function offsetRange(string $text, int $offset, int $length): Range
    {
        return new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + $length));
    }

    private function contains(string $text, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($text, $range->start()) && $offset <= $this->converter->toByteOffset($text, $range->end());
    }

    /** @return array<array-key, mixed> */
    private function completion(string $label, string $newText, string $detail, string $text, int $start, Position $end): array
    {
        $position = $this->converter->toPosition($text, $start);

        return ['label' => $label, 'kind' => 10, 'detail' => $detail, 'insertTextFormat' => 2, 'textEdit' => ['range' => ['start' => ['line' => $position->line(), 'character' => $position->character()], 'end' => ['line' => $end->line(), 'character' => $end->character()]], 'newText' => $newText]];
    }

    /** @return array{range: array<string, array<string, int>>, severity: int, source: string, code: string, message: string} */
    private function diagnostic(Range $range, int $severity, string $code, string $message): array
    {
        return ['range' => $this->range($range), 'severity' => $severity, 'source' => 'symfony', 'code' => $code, 'message' => $message];
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    private function range(Range $range): array
    {
        return ['start' => ['line' => $range->start()->line(), 'character' => $range->start()->character()], 'end' => ['line' => $range->end()->line(), 'character' => $range->end()->character()]];
    }
}
