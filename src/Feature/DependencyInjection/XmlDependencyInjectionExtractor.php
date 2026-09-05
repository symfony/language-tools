<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Xml\TolerantXmlParser;
use Symfony\Lsp\Parser\Xml\XmlElementStart;
use Symfony\Lsp\Parser\Xml\XmlParserInterface;
use Symfony\Lsp\Parser\Xml\XmlText;

final class XmlDependencyInjectionExtractor
{
    private const SERVICES_NAMESPACE = 'http://symfony.com/schema/dic/services';

    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly XmlParserInterface $parser = new TolerantXmlParser(),
    ) {
    }

    public function extract(string $uri, string $text): ?DependencyInjectionSourceFacts
    {
        if (!str_contains($text, self::SERVICES_NAMESPACE)) {
            return null;
        }
        $document = $this->parser->parse($text);
        $elements = $document->elements();
        $serviceElements = $this->serviceElements($elements);
        if ([] === $serviceElements) {
            return null;
        }

        $nearestServices = [];
        $tags = [];
        foreach ($elements as $element) {
            $nearestService = null === $element->parentIdentity ? null : ($nearestServices[$element->parentIdentity] ?? null);
            if (isset($serviceElements[$element->identity]) && 'service' === $element->localName) {
                $nearestService = $element->identity;
            } elseif (isset($serviceElements[$element->identity])
                && 'tag' === $element->localName
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
            if (!isset($serviceElements[$element->identity])) {
                continue;
            }
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
            if (!isset($serviceElements[$element->identity]) || 'argument' !== $element->localName || 'service' !== $element->attribute('type')?->value || null === $id = $element->attribute('id')) {
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
            if ($event instanceof XmlText && null !== $event->parentIdentity && isset($serviceElements[$event->parentIdentity])) {
                array_push($references, ...$this->parameterReferences($uri, $text, $event->raw, $event->startOffset));
            } elseif ($event instanceof XmlElementStart && isset($serviceElements[$event->identity])) {
                foreach ($event->attributes as $attribute) {
                    array_push($references, ...$this->parameterReferences($uri, $text, $attribute->value, $attribute->valueStartOffset));
                }
            }
        }

        return new DependencyInjectionSourceFacts($uri, $services, $parameters, $references);
    }

    /**
     * @param list<XmlElementStart> $elements
     *
     * @return array<int, true>
     */
    private function serviceElements(array $elements): array
    {
        $prefix = null;
        $documentElement = null;
        foreach ($elements as $element) {
            if (null !== $element->parentIdentity) {
                continue;
            }
            $namespace = $element->attribute(null === $element->prefix ? 'xmlns' : 'xmlns:'.$element->prefix);
            if ('container' === $element->localName && self::SERVICES_NAMESPACE === $namespace?->value) {
                $prefix = $element->prefix;
                $documentElement = $element;
            }
            break;
        }
        if (null === $documentElement) {
            return [];
        }

        $bound = [];
        $serviceElements = [];
        $declarationName = null === $prefix ? 'xmlns' : 'xmlns:'.$prefix;
        foreach ($elements as $element) {
            $isBound = null === $element->parentIdentity ? false : ($bound[$element->parentIdentity] ?? false);
            if (null !== $namespace = $element->attribute($declarationName)) {
                $isBound = self::SERVICES_NAMESPACE === $namespace->value;
            }
            $bound[$element->identity] = $isBound;
            if ($isBound && $element->prefix === $prefix) {
                $serviceElements[$element->identity] = true;
            }
        }

        return $serviceElements;
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
