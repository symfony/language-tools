<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\PositionConverter;

final class XmlDependencyInjectionExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
    ) {
    }

    public function extract(string $uri, string $text): ?DependencyInjectionSourceFacts
    {
        $source = $this->maskComments($text);
        if (!str_contains($source, 'symfony.com/schema/dic/services')) {
            return null;
        }
        $services = [];
        $references = [];

        preg_match_all('/<service\b([^>]*?)(\/>|>)/s', $source, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $i => [$attributes, $attributesOffset]) {
            $id = $this->attribute($attributes, 'id');
            if (null === $id) {
                continue;
            }
            [$name, $offset] = $id;
            $tags = [];
            if ('>' === $matches[2][$i][0]) {
                $bodyStart = $matches[2][$i][1] + 1;
                $bodyEnd = strpos($source, '</service', $bodyStart);
                $body = substr($source, $bodyStart, (false === $bodyEnd ? \strlen($source) : $bodyEnd) - $bodyStart);
                preg_match_all('/<tag\b([^>]*?)\/?>/s', $body, $tagMatches);
                foreach ($tagMatches[1] as $tagAttributes) {
                    $tag = $this->attribute($tagAttributes, 'name');
                    if (null !== $tag) {
                        $tags[] = $tag[0];
                    }
                }
                $tags = array_values(array_unique($tags));
            }
            $className = $this->attribute($attributes, 'class');
            $services[] = new ServiceDeclaration(
                $name,
                $uri,
                $this->positionConverter->toRange($text, $attributesOffset + $offset, \strlen($name)),
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
                        $this->positionConverter->toRange($text, $attributesOffset + $target[1], \strlen($target[0])),
                    );
                }
            }
        }

        $parameters = [];
        preg_match_all('/<parameter\b([^>]*?)>/s', $source, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as [$attributes, $attributesOffset]) {
            $key = $this->attribute($attributes, 'key');
            if (null !== $key) {
                $parameters[] = new ParameterDeclaration($key[0], $uri, $this->positionConverter->toRange($text, $attributesOffset + $key[1], \strlen($key[0])));
            }
        }

        preg_match_all('/<argument\b([^>]*?)\/?>/s', $source, $matches, \PREG_OFFSET_CAPTURE);
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
                $this->positionConverter->toRange($text, $attributesOffset + $id[1], \strlen($id[0])),
                null !== ($onInvalid = $this->attribute($attributes, 'on-invalid')) && 'exception' !== $onInvalid[0],
            );
        }

        preg_match_all('/%([^%\s"<>]+)%/', $source, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as [$name, $offset]) {
            if (str_starts_with($name, 'env(')) {
                continue;
            }
            $references[] = new DependencyInjectionReference(
                DependencyInjectionSymbolKind::Parameter,
                $name,
                $uri,
                $this->positionConverter->toRange($text, $offset, \strlen($name)),
            );
        }

        return new DependencyInjectionSourceFacts($uri, $services, $parameters, $references);
    }

    /** @return array{string, int}|null */
    private function attribute(string $attributes, string $name): ?array
    {
        if (!preg_match('/\b'.preg_quote($name, '/').'\s*=\s*([\'\"])(.*?)\1/s', $attributes, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return [$match[2][0], $match[2][1]];
    }

    private function maskComments(string $text): string
    {
        return preg_replace_callback('/<!--.*?(?:-->|$)/s', static function (array $match): string {
            return preg_replace('/[^\r\n]/', ' ', $match[0]) ?? $match[0];
        }, $text) ?? $text;
    }
}
