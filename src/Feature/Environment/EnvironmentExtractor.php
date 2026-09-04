<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\CommentParserRegistry;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\UriToPathConverter;

final class EnvironmentExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly CommentParserRegistry $comments,
        private readonly YamlDocumentParser $yamlParser,
        private readonly EnvironmentExpressionParser $expressionParser = new EnvironmentExpressionParser(),
    ) {
    }

    public function extract(SourceDocument $document): EnvironmentSourceFacts
    {
        $declarations = [];
        $references = [];
        $malformedExpressions = [];
        $path = $this->uriToPathConverter->convert($document->uri);
        if ('dotenv' === $document->languageId || (null !== $path && str_starts_with(basename($path), '.env'))) {
            preg_match_all('/^(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=(.*)$/m', $document->text, $matches, \PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$name, $offset]) {
                $declarations[] = new EnvironmentDeclaration(
                    $name,
                    $document->uri,
                    $this->converter->toRange($document->text, $offset, \strlen($name)),
                    true,
                );
            }
            preg_match_all('/(?<!\\\\)\$(?:\{)?([A-Za-z_][A-Za-z0-9_]*)/', $document->text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                [$name, $offset] = $match[1];
                $references[] = new EnvironmentReference($name, $document->uri, $this->converter->toRange($document->text, $offset, \strlen($name)), []);
            }
        }
        if ('yaml' === $document->languageId) {
            foreach ($this->yamlParser->parseDocument($document->text)->scalars as $scalar) {
                $contentOffset = $scalar->contentStartByte - $scalar->startByte;
                $scalarText = substr($scalar->raw, $contentOffset, $scalar->contentEndByte - $scalar->contentStartByte);
                array_push($references, ...$this->references($document->uri, $document->text, $scalarText, $scalar->contentStartByte));
                array_push($malformedExpressions, ...$this->malformedExpressions($document->text, $scalarText, $scalar->contentStartByte));
            }
        } else {
            $source = $this->comments->mask($document->languageId, $document->text);
            if (\in_array($document->languageId, ['php', 'twig', 'xml'], true)) {
                array_push($references, ...$this->references($document->uri, $document->text, $source));
            }
            array_push($malformedExpressions, ...$this->malformedExpressions($document->text, $source));
        }

        return new EnvironmentSourceFacts($document->uri, $declarations, $references, $malformedExpressions);
    }

    /** @return list<MalformedEnvironmentExpression> */
    private function malformedExpressions(string $text, string $source, int $baseOffset = 0): array
    {
        preg_match_all('/%env\([^\)\r\n]*%/', $source, $matches, \PREG_OFFSET_CAPTURE);
        $expressions = [];
        foreach ($matches[0] as [$expression, $offset]) {
            $expressions[] = new MalformedEnvironmentExpression(
                $this->converter->toRange($text, $baseOffset + $offset, \strlen($expression)),
            );
        }

        return $expressions;
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
