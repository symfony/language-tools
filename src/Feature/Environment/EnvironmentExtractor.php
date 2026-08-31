<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\UriToPathConverter;

final class EnvironmentExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly TwigCommentParser $commentParser,
        private readonly PhpCommentParserInterface $phpComments,
        private readonly YamlDocumentParser $yamlParser,
        private readonly XmlCommentParser $xmlComments,
        private readonly EnvironmentExpressionParser $expressionParser = new EnvironmentExpressionParser(),
    ) {
    }

    public function extract(string $uri, string $languageId, string $text): EnvironmentSourceFacts
    {
        $declarations = [];
        $references = [];
        $path = $this->uriToPathConverter->convert($uri);
        if ('dotenv' === $languageId || (null !== $path && str_starts_with(basename($path), '.env'))) {
            preg_match_all('/^(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=(.*)$/m', $text, $matches, \PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$name, $offset]) {
                $declarations[] = new EnvironmentDeclaration(
                    $name,
                    $uri,
                    $this->converter->toRange($text, $offset, \strlen($name)),
                    true,
                );
            }
            preg_match_all('/(?<!\\\\)\$(?:\{)?([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                [$name, $offset] = $match[1];
                $references[] = new EnvironmentReference($name, $uri, $this->converter->toRange($text, $offset, \strlen($name)), []);
            }
        }
        if ('yaml' === $languageId) {
            foreach ($this->yamlParser->parseDocument($text)->scalars as $scalar) {
                $contentOffset = $scalar->contentStartByte - $scalar->startByte;
                $scalarText = substr($scalar->raw, $contentOffset, $scalar->contentEndByte - $scalar->contentStartByte);
                array_push($references, ...$this->references($uri, $text, $scalarText, $scalar->contentStartByte));
            }
        } elseif (\in_array($languageId, ['php', 'twig', 'xml'], true)) {
            $referenceText = match ($languageId) {
                'twig' => $this->commentParser->mask($text),
                'php' => $this->phpComments->mask($text),
                'xml' => $this->xmlComments->mask($text),
            };
            array_push($references, ...$this->references($uri, $text, $referenceText));
        }

        return new EnvironmentSourceFacts($uri, $declarations, $references);
    }

    /** @return list<EnvironmentReference> */
    private function references(string $uri, string $text, string $referenceText, int $baseOffset = 0): array
    {
        $references = [];
        foreach ($this->expressionParser->parseAll($referenceText, $baseOffset) as $expression) {
            $references[] = new EnvironmentReference(
                $expression->variableName,
                $uri,
                $this->converter->toRange($text, $expression->variableRange->startByte, $expression->variableRange->endByte - $expression->variableRange->startByte),
                $expression->processorChain,
            );
        }

        return $references;
    }
}
