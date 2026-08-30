<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;

final class YamlDependencyInjectionExtractor
{
    public function __construct(
        private readonly YamlDocumentParser $parser,
        private readonly YamlDependencyInjectionDeclarationExtractor $declarationExtractor,
        private readonly YamlDependencyInjectionReferenceExtractor $referenceExtractor,
    ) {
    }

    public function extract(string $uri, string $text, ?string $environment = null): DependencyInjectionSourceFacts
    {
        $document = $this->parser->parseDocument($text);
        [$services, $parameters] = $this->declarationExtractor->extract($uri, $text, $document, $environment);

        return new DependencyInjectionSourceFacts(
            $uri,
            $services,
            $parameters,
            $this->referenceExtractor->extract($uri, $text, $document, $environment),
        );
    }
}
