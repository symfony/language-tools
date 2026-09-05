<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Xml\TolerantXmlParser;
use Symfony\Lsp\Parser\Xml\XmlElementStart;
use Symfony\Lsp\Parser\Xml\XmlParserInterface;
use Symfony\Lsp\Parser\Xml\XmlText;

final class XmlDependencyInjectionExtractor
{
    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly XmlParserInterface $parser = new TolerantXmlParser(),
    ) {
    }

    public function extract(string $uri, string $text): ?DependencyInjectionSourceFacts
    {
        if (!str_contains($text, 'symfony.com/schema/dic/services')) {
            return null;
        }
        $document = $this->parser->parse($text);
        $elements = $document->elements();
        if (!$this->hasServicesSchema($elements)) {
            return null;
        }

        $nearestServices = [];
        $tags = [];
        foreach ($elements as $element) {
            $nearestService = null === $element->parentIdentity ? null : ($nearestServices[$element->parentIdentity] ?? null);
            if ('service' === $element->localName) {
                $nearestService = $element->identity;
            } elseif ('tag' === $element->localName
                && null !== $nearestService
                && $element->parentIdentity === $nearestService
                && null !== $name = $element->attribute('name')
            ) {
                $tags[$nearestService][$name->value] = true;
            }
            $nearestServices[$element->identity] = $nearestService;
        }

        $services = [];
        $parameters = [];
        $references = [];
        foreach ($elements as $element) {
            if ('service' === $element->localName) {
                $id = $element->attribute('id');
                if (null === $id) {
                    continue;
                }
                $class = $element->attribute('class');
                $alias = $element->attribute('alias');
                $decorates = $element->attribute('decorates');
                $services[] = new ServiceDeclaration(
                    $id->value,
                    $uri,
                    $this->positionConverter->toRange($text, $id->valueStartOffset, $id->valueEndOffset - $id->valueStartOffset),
                    null === $class ? (str_contains($id->value, '\\') ? ltrim($id->value, '\\') : null) : $class->value,
                    $alias?->value,
                    $decorates?->value,
                    array_keys($tags[$element->identity] ?? []),
                );
                foreach ([$alias, $decorates] as $target) {
                    if (null !== $target) {
                        $references[] = new DependencyInjectionReference(
                            DependencyInjectionSymbolKind::Service,
                            $target->value,
                            $uri,
                            $this->positionConverter->toRange($text, $target->valueStartOffset, $target->valueEndOffset - $target->valueStartOffset),
                        );
                    }
                }
                continue;
            }
            if ('parameter' === $element->localName && null !== $key = $element->attribute('key')) {
                $parameters[] = new ParameterDeclaration(
                    $key->value,
                    $uri,
                    $this->positionConverter->toRange($text, $key->valueStartOffset, $key->valueEndOffset - $key->valueStartOffset),
                );
            }
        }
        foreach ($elements as $element) {
            if ('argument' !== $element->localName || 'service' !== $element->attribute('type')?->value || null === $id = $element->attribute('id')) {
                continue;
            }
            $onInvalid = $element->attribute('on-invalid');
            $references[] = new DependencyInjectionReference(
                DependencyInjectionSymbolKind::Service,
                $id->value,
                $uri,
                $this->positionConverter->toRange($text, $id->valueStartOffset, $id->valueEndOffset - $id->valueStartOffset),
                null !== $onInvalid && 'exception' !== $onInvalid->value,
            );
        }

        foreach ($document->events as $event) {
            if ($event instanceof XmlText) {
                array_push($references, ...$this->parameterReferences($uri, $text, $event->raw, $event->startOffset));
            } elseif ($event instanceof XmlElementStart) {
                foreach ($event->attributes as $attribute) {
                    array_push($references, ...$this->parameterReferences($uri, $text, $attribute->value, $attribute->valueStartOffset));
                }
            }
        }

        return new DependencyInjectionSourceFacts($uri, $services, $parameters, $references);
    }

    /** @param list<XmlElementStart> $elements */
    private function hasServicesSchema(array $elements): bool
    {
        foreach ($elements as $element) {
            foreach ($element->attributes as $attribute) {
                if (('xmlns' === $attribute->qualifiedName || str_starts_with($attribute->qualifiedName, 'xmlns:'))
                    && str_contains($attribute->value, 'symfony.com/schema/dic/services')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<DependencyInjectionReference> */
    private function parameterReferences(string $uri, string $text, string $value, int $valueOffset): array
    {
        preg_match_all('/%([^%\s"<>]+)%/', $value, $matches, \PREG_OFFSET_CAPTURE);
        $references = [];
        foreach ($matches[1] as [$name, $offset]) {
            if (str_starts_with($name, 'env(')) {
                continue;
            }
            $references[] = new DependencyInjectionReference(
                DependencyInjectionSymbolKind::Parameter,
                $name,
                $uri,
                $this->positionConverter->toRange($text, $valueOffset + $offset, \strlen($name)),
            );
        }

        return $references;
    }
}
