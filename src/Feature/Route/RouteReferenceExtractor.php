<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class RouteReferenceExtractor
{
    private const ABSTRACT_CONTROLLER = 'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController';
    private const METHODS = [
        'generate',
        'generateUrl',
        'redirectToRoute',
    ];

    public function __construct(
        private readonly PositionConverter $positionConverter,
        private readonly PhpParserInterface $parser,
    ) {
    }

    /**
     * @return list<RouteReference>
     */
    public function extract(string $text, ?DependencyInjectionSourceIndex $classIndex = null): array
    {
        $document = $this->parser->parse($text);

        return array_values(array_filter(
            $this->extractFromDocument($text, $document),
            fn (RouteReference $reference): bool => $this->isControllerClass($reference->controllerClass(), $document, $classIndex),
        ));
    }

    /**
     * @return list<RouteReference>
     */
    public function extractCandidates(string $text): array
    {
        $document = $this->parser->parse($text);

        return $this->extractFromDocument($text, $document);
    }

    public function isSymfonyReceiver(string $source, DependencyInjectionSourceIndex $classIndex): bool
    {
        $document = $this->parser->parse($source);
        $receiver = RoutePhpReceiver::resolve($source, \strlen($source), $document->typeDeclarations());

        return null !== $receiver && $this->isControllerClass($receiver->controllerClass(), $document, $classIndex);
    }

    public function at(string $text, int $byteOffset, ?DependencyInjectionSourceIndex $classIndex = null): ?RouteReference
    {
        foreach ($this->extract($text, $classIndex) as $reference) {
            $start = $this->positionConverter->toByteOffset($text, $reference->range()->start());
            $end = $this->positionConverter->toByteOffset($text, $reference->range()->end());
            if ($byteOffset >= $start && $byteOffset <= $end) {
                return $reference;
            }
        }

        return null;
    }

    /**
     * @return list<RouteReference>
     */
    private function extractFromDocument(string $text, PhpDocument $document): array
    {
        preg_match_all(
            '/(?:->|::)('.implode('|', self::METHODS).')\s*\(\s*([\'"])([^\'"]+)\2/s',
            $text,
            $matches,
            \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE,
        );
        $references = [];

        foreach ($matches as $match) {
            $methodOffset = $match[1][1];
            $receiverOffset = $methodOffset - 2;
            $receiver = RoutePhpReceiver::resolve(
                substr($text, 0, $receiverOffset),
                $receiverOffset,
                $document->typeDeclarations(),
            );
            if (null === $receiver) {
                continue;
            }

            $name = $match[3][0];
            $offset = $match[3][1];
            $references[] = new RouteReference(
                $name,
                new Range(
                    $this->positionConverter->toPosition($text, $offset),
                    $this->positionConverter->toPosition($text, $offset + \strlen($name)),
                ),
                $this->providedParameters(substr($text, $match[0][1] + \strlen($match[0][0]))),
                $receiver->controllerClass(),
            );
        }

        return $references;
    }

    private function isControllerClass(?string $className, PhpDocument $document, ?DependencyInjectionSourceIndex $classIndex): bool
    {
        if (null === $className) {
            return true;
        }
        if (null !== $classIndex && [] !== $classIndex->classDeclarations($className)) {
            return $classIndex->isSubclassOf($className, self::ABSTRACT_CONTROLLER);
        }

        $types = [];
        foreach ($document->typeDeclarations() as $type) {
            $types[strtolower(ltrim($type->name(), '\\'))] = $type;
        }
        $visited = [];
        while (!isset($visited[strtolower($className)])) {
            $className = ltrim($className, '\\');
            if (0 === strcasecmp(self::ABSTRACT_CONTROLLER, $className)) {
                return true;
            }
            $classKey = strtolower($className);
            $type = $types[$classKey] ?? null;
            if (null === $type) {
                return 0 === strcasecmp('AbstractController', $className);
            }
            $visited[$classKey] = true;
            if (null === $className = $type->parentClassName()) {
                return false;
            }
        }

        return false;
    }

    /**
     * @return list<string>|null
     */
    private function providedParameters(string $afterRouteName): ?array
    {
        if (preg_match('/^\s*\)/', $afterRouteName)) {
            return [];
        }

        if (!preg_match('/^\s*,\s*\[([^\[\]]*)\]\s*[,)]/s', $afterRouteName, $parameters)) {
            return null;
        }

        preg_match_all('/([\'"])([^\'"]+)\1\s*=>/', $parameters[1], $keys);

        return array_values(array_unique($keys[2]));
    }
}
