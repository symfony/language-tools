<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class XmlDependencyInjectionExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
    ) {
    }

    public function extract(string $uri, string $text): ?DependencyInjectionSourceFacts
    {
        if (!str_contains($text, 'symfony.com/schema/dic/services')) {
            return null;
        }
        $services = [];
        $references = [];

        preg_match_all('/<service\b([^>]*?)(\/>|>)/s', $text, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $i => [$attributes, $attributesOffset]) {
            $id = $this->attribute($attributes, 'id');
            if (null === $id) {
                continue;
            }
            [$name, $offset] = $id;
            $tags = [];
            if ('>' === $matches[2][$i][0]) {
                $bodyStart = $matches[2][$i][1] + 1;
                $bodyEnd = strpos($text, '</service', $bodyStart);
                $body = substr($text, $bodyStart, (false === $bodyEnd ? \strlen($text) : $bodyEnd) - $bodyStart);
                preg_match_all('/<tag\b[^>]*?\bname="([^"]+)"/s', $body, $tagMatches);
                $tags = array_values(array_unique(array_filter($tagMatches[1], 'is_string')));
            }
            $className = $this->attribute($attributes, 'class');
            $services[] = new ServiceDeclaration(
                $name,
                $uri,
                $this->range($text, $attributesOffset + $offset, \strlen($name)),
                null !== $className ? $className[0] : (str_contains($name, '\\') ? ltrim($name, '\\') : null),
                $this->attribute($attributes, 'alias')[0] ?? null,
                $this->attribute($attributes, 'decorates')[0] ?? null,
                $tags,
            );
            foreach (['alias', 'decorates'] as $referencing) {
                $target = $this->attribute($attributes, $referencing);
                if (null !== $target) {
                    $references[] = new DependencyInjectionReference(
                        DependencyInjectionSymbolKind::Service,
                        $target[0],
                        $uri,
                        $this->range($text, $attributesOffset + $target[1], \strlen($target[0])),
                    );
                }
            }
        }

        $parameters = [];
        preg_match_all('/<parameter\b[^>]*?\bkey="([^"]+)"/s', $text, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as [$name, $offset]) {
            $parameters[] = new ParameterDeclaration($name, $uri, $this->range($text, $offset, \strlen($name)));
        }

        preg_match_all('/<argument\b([^>]*?)\/?>/s', $text, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as [$attributes, $attributesOffset]) {
            $type = $this->attribute($attributes, 'type');
            $id = $this->attribute($attributes, 'id');
            if (null === $type || 'service' !== $type[0] || null === $id) {
                continue;
            }
            $references[] = new DependencyInjectionReference(
                DependencyInjectionSymbolKind::Service,
                $id[0],
                $uri,
                $this->range($text, $attributesOffset + $id[1], \strlen($id[0])),
                null !== ($onInvalid = $this->attribute($attributes, 'on-invalid')) && 'exception' !== $onInvalid[0],
            );
        }

        preg_match_all('/%([^%\s"<>]+)%/', $text, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as [$name, $offset]) {
            if (str_starts_with($name, 'env(')) {
                continue;
            }
            $references[] = new DependencyInjectionReference(
                DependencyInjectionSymbolKind::Parameter,
                $name,
                $uri,
                $this->range($text, $offset, \strlen($name)),
            );
        }

        return new DependencyInjectionSourceFacts($uri, $services, $parameters, $references);
    }

    /** @return array{string, int}|null */
    private function attribute(string $attributes, string $name): ?array
    {
        if (!preg_match('/\b'.preg_quote($name, '/').'="([^"]*)"/', $attributes, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return [$match[1][0], $match[1][1]];
    }

    private function range(string $text, int $offset, int $length): Range
    {
        return new Range(
            $this->positionConverter->toPosition($text, $offset),
            $this->positionConverter->toPosition($text, $offset + $length),
        );
    }
}
