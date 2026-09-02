<?php

namespace Symfony\Lsp\Tests\Feature\Translation;

use Microsoft\PhpParser\Parser;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Translation\PhpTranslationCatalogParser;
use Symfony\Lsp\Feature\Translation\PhpTranslationReferenceExtractor;
use Symfony\Lsp\Feature\Translation\TranslationCatalogExtractor;
use Symfony\Lsp\Feature\Translation\TranslationExtractor;
use Symfony\Lsp\Feature\Translation\TranslationParameterAnalyzer;
use Symfony\Lsp\Feature\Translation\TwigTranslationReferenceExtractor;
use Symfony\Lsp\Parser\Php\PhpLiteralArrayKeyParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigArgumentParser;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\UriToPathConverter;

final class TranslationExtractorTestFactory
{
    public static function create(?PositionConverter $converter = null, ?TwigCommentParser $twigComments = null): TranslationExtractor
    {
        $converter ??= new PositionConverter();
        $twigComments ??= new TwigCommentParser();
        $treeSitter = new NativeTreeSitterParser(new TreeSitterResultDecoder());
        $parameters = new TranslationParameterAnalyzer(new PhpLiteralArrayKeyParser());

        return new TranslationExtractor(
            new TranslationCatalogExtractor($converter, new UriToPathConverter(), new YamlDocumentParser($treeSitter), new PhpTranslationCatalogParser()),
            new PhpTranslationReferenceExtractor($converter, new TolerantPhpParser(new Parser()), $parameters),
            new TwigTranslationReferenceExtractor($converter, new TwigDocumentParser($treeSitter, $twigComments), new TwigCallArgumentResolver(new TwigArgumentParser()), $twigComments, $parameters),
        );
    }
}
