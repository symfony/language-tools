<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\HoverProviderInterface;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class ConfigurationHoverProvider implements HoverProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly ConfigurationIndexRegistry $indexes,
        private readonly ConfigurationPathResolver $paths,
        private readonly YamlConfigurationParser $yaml,
    ) {
    }

    public function hover(array $params): ?array
    {
        $request = $this->resolver->resolvePositioned($params);
        if (null === $request) {
            return null;
        }
        $offset = $this->converter->toByteOffset($request->document->text(), $request->position);
        $index = $this->indexes->forProject($request->project);
        if ('php' === $request->document->languageId()) {
            $resolved = $this->paths->resolvePhpNode($request->document, $index, $offset);

            return null === $resolved ? null : $this->protocol->markdownHover($this->description($resolved[0], $resolved[1]));
        }
        if ('xml' === $request->document->languageId()) {
            $resolved = $this->paths->resolveXmlNode($request->document, $index, $offset);

            return null === $resolved ? null : $this->protocol->markdownHover($this->description($resolved[0], $resolved[1]));
        }
        if ('yaml' !== $request->document->languageId()) {
            return null;
        }
        foreach ($this->yaml->parse($request->document->text(), $index) as $occurrence) {
            if (!$this->contains($request->document->text(), $occurrence->keyRange(), $offset)) {
                continue;
            }
            $node = $index->find($occurrence->path(), $occurrence->sequenceItem());

            return null === $node ? null : $this->protocol->markdownHover($this->description($occurrence->path(), $node));
        }

        return null;
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
            $allowedValues = [];
            foreach ($node->allowedValues() as $value) {
                $allowedValues[] = match ($value) {
                    true => 'true',
                    false => 'false',
                    null => 'null',
                    default => (string) $value,
                };
            }
            $lines[] = '';
            $lines[] = 'Allowed values: `'.implode('`, `', $allowedValues).'`';
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

    private function contains(string $text, Range $range, int $offset): bool
    {
        return $offset >= $this->converter->toByteOffset($text, $range->start()) && $offset <= $this->converter->toByteOffset($text, $range->end());
    }
}
