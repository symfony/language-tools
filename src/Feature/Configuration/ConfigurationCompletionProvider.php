<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\CompletionProviderInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class ConfigurationCompletionProvider implements CompletionProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly ConfigurationIndexRegistry $indexes,
        private readonly YamlConfigurationParser $yaml,
        private readonly PhpConfigurationAnalyzer $php,
        private readonly XmlConfigurationAnalyzer $xml,
    ) {
    }

    public function complete(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }

        return match ($request->document->languageId) {
            'yaml' => $this->completeYaml($request->document, $request->project, $request->position),
            'php' => $this->completePhp($request->document, $request->project, $request->position),
            'xml' => $this->completeXml($request->document, $request->project, $request->position),
            default => null,
        };
    }

    /** @return list<array<array-key, mixed>>|null */
    private function completeYaml(Document $document, Project $project, Position $position): ?array
    {
        $offset = $this->converter->toByteOffset($document->text, $position);
        $before = substr($document->text, 0, $offset);
        $lineStart = strrpos($before, "\n");
        $lineStart = false === $lineStart ? 0 : $lineStart + 1;
        $line = substr($before, $lineStart);
        $index = $this->indexes->forProject($project);
        foreach ($this->yaml->parse($document->text, $index) as $occurrence) {
            if (!$this->converter->containsByteOffset($document->text, $occurrence->valueRange, $offset, inclusiveEnd: true)) {
                continue;
            }
            $node = $index->find($occurrence->path, $occurrence->sequenceDepths, $occurrence->literalDepths);
            if (null === $node || ([] === $node->allowedValues && [] === $node->allowedEnumCases)) {
                continue;
            }
            $prefix = trim(substr($document->text, $this->converter->toByteOffset($document->text, $occurrence->valueRange->start), $offset));
            $items = [];
            if ([] !== $node->allowedValues) {
                foreach ($node->allowedValues as $value) {
                    $value = $this->formatValue($value);
                    $items[] = $this->completion($value, $value, 'Allowed value', $document->text, $offset - \strlen($prefix), $position);
                }
            } else {
                foreach ($node->allowedEnumCases as $case) {
                    $value = '!php/enum '.$case;
                    $items[] = $this->completion($value, $value, 'Allowed value', $document->text, $offset - \strlen($prefix), $position);
                }
            }

            return $items;
        }
        if (!preg_match('/^(\s*)(?:-\s+)?([A-Za-z_][A-Za-z0-9_.-]*)?$/', $line, $match)) {
            return null;
        }
        $indent = \strlen($match[1]);
        $prefix = $match[2] ?? '';
        $parent = [];
        $parentSequenceDepths = [];
        $previous = array_reverse($this->yaml->parse(substr($document->text, 0, $lineStart), $index));
        foreach ($previous as $occurrence) {
            if ($occurrence->keyRange->start->character < $indent) {
                $parent = $occurrence->path;
                $parentSequenceDepths = $occurrence->sequenceDepths;
                break;
            }
        }
        $nodes = [] === $parent ? array_values($index->roots()) : $this->completionChildren($index->find($parent, $parentSequenceDepths));
        $items = [];
        foreach ($nodes as $node) {
            if (str_starts_with($node->name, $prefix)) {
                $items[] = $this->completion($node->name, $node->name.':'.$this->yamlSnippet($node), $this->shortDescription($node), $document->text, $offset - \strlen($prefix), $position);
            }
        }

        return $items;
    }

    /** @return list<array<array-key, mixed>>|null */
    private function completePhp(Document $document, Project $project, Position $position): ?array
    {
        $offset = $this->converter->toByteOffset($document->text, $position);
        $context = $this->php->completionContext($document->text, $offset);
        if (null === $context) {
            return null;
        }
        $parent = $this->indexes->forProject($project)->find($context['path']);
        if (null === $parent) {
            return null;
        }
        $items = [];
        foreach ($this->completionChildren($parent) as $node) {
            $method = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $node->name))));
            if (str_starts_with($method, $context['prefix'])) {
                $items[] = $this->completion($method, $method.'('.$this->phpSnippet($node).')', $this->shortDescription($node), $document->text, $context['start'], $position);
            }
        }

        return $items;
    }

    /** @return list<array<array-key, mixed>>|null */
    private function completeXml(Document $document, Project $project, Position $position): ?array
    {
        $offset = $this->converter->toByteOffset($document->text, $position);
        $index = $this->indexes->forProject($project);
        $context = $this->xml->completionContext($document->text, $index, $offset);
        if (null === $context) {
            return null;
        }
        if ($context['attribute']) {
            $items = [];
            foreach ($this->completionChildren(null === $context['path'] ? null : $index->find($context['path'])) as $node) {
                $xmlName = str_replace('_', '-', $node->name);
                if (str_starts_with($xmlName, $context['prefix'])) {
                    $items[] = $this->completion($xmlName, $xmlName.'="${1}"', $this->shortDescription($node), $document->text, $context['start'], $position);
                }
            }

            return $items;
        }
        if ('' !== $context['alias'] && [] === $context['path'] && isset($index->roots()[$context['alias']])) {
            return str_starts_with('config', $context['prefix']) ? [$this->completion('config', $context['alias'].':config>', 'Bundle configuration root', $document->text, $context['start'], $position)] : [];
        }
        $nodes = [] === $context['path'] ? array_values($index->roots()) : $this->completionChildren(null === $context['path'] ? null : $index->find($context['path']));
        $items = [];
        foreach ($nodes as $node) {
            $xmlName = str_replace('_', '-', $node->name);
            if (str_starts_with($xmlName, $context['prefix'])) {
                $newText = ('' !== $context['alias'] ? $context['alias'].':' : '').$xmlName.'>';
                $items[] = $this->completion($xmlName, $newText, $this->shortDescription($node), $document->text, $context['start'], $position);
            }
        }

        return $items;
    }

    /** @return list<ConfigurationNode> */
    private function completionChildren(?ConfigurationNode $node): array
    {
        if (null === $node) {
            return [];
        }
        if ([] !== $node->children) {
            return $node->children;
        }

        return $node->prototype->children ?? [];
    }

    private function phpSnippet(ConfigurationNode $node): string
    {
        if ('array' === $node->type) {
            return '';
        }
        if ('boolean' === $node->type) {
            return '${1:true}';
        }
        if ([] !== $node->allowedValues) {
            $value = $node->allowedValues[0];
            $snippet = '${1:'.$this->formatValue($value).'}';

            return \is_string($value) ? "'".$snippet."'" : $snippet;
        }

        return '${1}';
    }

    private function yamlSnippet(ConfigurationNode $node): string
    {
        return match ($node->type) {
            'boolean' => ' ${1:true}', 'integer', 'float', 'scalar', 'enum', 'variable' => ' ${1}', default => '',
        };
    }

    private function formatValue(string|int|float|bool|null $value): string
    {
        return match ($value) {
            true => 'true',
            false => 'false',
            null => 'null',
            default => (string) $value,
        };
    }

    private function shortDescription(ConfigurationNode $node): string
    {
        return $node->type.(null !== $node->info ? ' - '.$node->info : '');
    }

    /** @return array<array-key, mixed> */
    private function completion(string $label, string $newText, string $detail, string $text, int $start, Position $end): array
    {
        $position = $this->converter->toPosition($text, $start);

        return ['label' => $label, 'kind' => 10, 'detail' => $detail, 'insertTextFormat' => 2, 'textEdit' => $this->protocol->textEdit(new Range($position, $end), $newText)];
    }
}
