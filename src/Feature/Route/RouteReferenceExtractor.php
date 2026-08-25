<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;
use Symfony\Lsp\Parser\QuotedArgumentMatcher;

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
        private readonly QuotedArgumentMatcher $matcher,
        private readonly PhpCommentParserInterface $phpComments,
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
        $masked = $this->phpComments->mask($text);
        $references = [];
        foreach ($this->matcher->methodCalls($masked, self::METHODS) as $call) {
            $receiverOffset = $call->nameOffset - 2;
            $receiver = RoutePhpReceiver::resolve(
                substr($masked, 0, $receiverOffset),
                $receiverOffset,
                $document->typeDeclarations(),
            );
            if (null === $receiver) {
                continue;
            }

            $references[] = new RouteReference(
                $call->value,
                $call->range,
                $this->providedParameters(substr($masked, $call->end())),
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

        if (null === $parameters = $this->parameterArray($afterRouteName)) {
            return null;
        }

        return $this->literalParameterKeys($parameters);
    }

    private function parameterArray(string $afterRouteName): ?string
    {
        if (!preg_match('/^\s*,\s*\[/', $afterRouteName, $match)) {
            return null;
        }
        $open = strpos($afterRouteName, '[', \strlen($match[0]) - 1);
        if (false === $open || null === $close = $this->matchingBracket($afterRouteName, $open)) {
            return null;
        }
        $tail = ltrim(substr($afterRouteName, $close + 1));
        if ('' === $tail || !\in_array($tail[0], [',', ')'], true)) {
            return null;
        }

        return substr($afterRouteName, $open + 1, $close - $open - 1);
    }

    /** @return list<string>|null */
    private function literalParameterKeys(string $parameters): ?array
    {
        $keys = [];
        $depth = 0;
        $literalKey = null;
        $keyIsLiteral = true;
        $keyParsed = false;
        foreach (token_get_all('<?php '.$parameters) as $token) {
            if (\is_array($token)) {
                if (\T_ELLIPSIS === $token[0] && 0 === $depth) {
                    return null;
                }
                if (\in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    if (0 === $depth && !$keyParsed) {
                        $keyIsLiteral = false;
                    }
                    ++$depth;

                    continue;
                }
                if (0 !== $depth || \in_array($token[0], [\T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                if (\T_DOUBLE_ARROW === $token[0] && !$keyParsed) {
                    if (!$keyIsLiteral || null === $literalKey) {
                        return null;
                    }
                    $quote = $literalKey[0];
                    $value = substr($literalKey, 1, -1);
                    $keys[] = "'" === $quote ? strtr($value, ['\\\\' => '\\', "\\'" => "'"]) : PhpStringLiteralDecoder::decodeDoubleQuoted($value);
                    $keyParsed = true;
                } elseif (!$keyParsed) {
                    if (\T_CONSTANT_ENCAPSED_STRING === $token[0] && null === $literalKey) {
                        $literalKey = $token[1];
                    } else {
                        $keyIsLiteral = false;
                    }
                }
                continue;
            }
            if (\in_array($token, ['(', '[', '{'], true)) {
                if (0 === $depth && !$keyParsed) {
                    $keyIsLiteral = false;
                }
                ++$depth;
            } elseif (\in_array($token, [')', ']', '}'], true)) {
                --$depth;
            } elseif (0 === $depth && ',' === $token) {
                $literalKey = null;
                $keyIsLiteral = true;
                $keyParsed = false;
            } elseif (0 === $depth && !$keyParsed) {
                $keyIsLiteral = false;
            }
        }

        return array_values(array_unique($keys));
    }

    private function matchingBracket(string $text, int $open): ?int
    {
        $depth = 1;
        $quote = null;
        $escaped = false;
        for ($offset = $open + 1, $length = \strlen($text); $offset < $length; ++$offset) {
            $character = $text[$offset];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }
                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
            } elseif ('[' === $character) {
                ++$depth;
            } elseif (']' === $character && 0 === --$depth) {
                return $offset;
            }
        }

        return null;
    }
}
