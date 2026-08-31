<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class EnvironmentDiagnosticProvider implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentContextResolver $resolver,
        private readonly PositionConverter $converter,
        private readonly LspProtocolMapper $protocol,
        private readonly EnvironmentIndexRegistry $indexes,
        private readonly EnvironmentExtractor $extractor,
        private readonly EnvironmentProcessorChainValidator $processorChainValidator,
        private readonly TwigCommentParser $commentParser,
        private readonly PhpCommentParserInterface $phpComments,
        private readonly YamlDocumentParser $yamlParser,
        private readonly XmlCommentParser $xmlComments,
    ) {
    }

    public function name(): string
    {
        return 'environment';
    }

    public function diagnostics(array $params): ?array
    {
        $request = $this->resolver->resolveDocument($params);
        if (null === $request) {
            return null;
        }
        $index = $this->indexes->forProject($request->project);
        $diagnostics = [];
        foreach ($this->extractor->extract($request->document->uri, $request->document->languageId, $request->document->text)->references as $reference) {
            foreach ($this->processorChainValidator->validate($reference->processors, $index) as $issue) {
                $diagnostics[] = $this->protocol->diagnostic($reference->range, 1, $issue->code, $issue->message);
            }
        }
        foreach ($this->malformedExpressions($request->document->languageId, $request->document->text) as [$expression, $offset]) {
            $range = new Range($this->converter->toPosition($request->document->text, $offset), $this->converter->toPosition($request->document->text, $offset + \strlen($expression)));
            $diagnostics[] = $this->protocol->diagnostic($range, 1, 'env.malformed_chain', 'Malformed environment expression; expected ")%".');
        }

        return $diagnostics;
    }

    /** @return iterable<array{string, int}> */
    private function malformedExpressions(string $languageId, string $text): iterable
    {
        if ('yaml' === $languageId) {
            foreach ($this->yamlParser->parseDocument($text)->scalars as $scalar) {
                $contentOffset = $scalar->contentStartByte - $scalar->startByte;
                $scalarText = substr($scalar->raw, $contentOffset, $scalar->contentEndByte - $scalar->contentStartByte);
                preg_match_all('/%env\([^\)\r\n]*%/', $scalarText, $malformed, \PREG_OFFSET_CAPTURE);
                foreach ($malformed[0] as [$expression, $offset]) {
                    yield [$expression, $scalar->contentStartByte + $offset];
                }
            }

            return;
        }

        preg_match_all('/%env\([^\)\r\n]*%/', $this->commentFreeText($languageId, $text), $malformed, \PREG_OFFSET_CAPTURE);
        foreach ($malformed[0] as $match) {
            yield $match;
        }
    }

    private function commentFreeText(string $languageId, string $text): string
    {
        return match ($languageId) {
            'twig' => $this->commentParser->mask($text),
            'php' => $this->phpComments->mask($text),
            'xml' => $this->xmlComments->mask($text),
            default => $text,
        };
    }
}
