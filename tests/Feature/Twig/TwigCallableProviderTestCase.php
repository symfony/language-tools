<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\ProjectDocumentReader;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\Twig\TwigCallableArgumentAnalyzer;
use Symfony\Lsp\Feature\Twig\TwigCallableCompletionProvider;
use Symfony\Lsp\Feature\Twig\TwigCallableDeclarationExtractor;
use Symfony\Lsp\Feature\Twig\TwigCallableDiagnosticProvider;
use Symfony\Lsp\Feature\Twig\TwigCallableMethodResolver;
use Symfony\Lsp\Feature\Twig\TwigCallableReferenceExtractor;
use Symfony\Lsp\Feature\Twig\TwigCallableRelationshipProvider;
use Symfony\Lsp\Feature\Twig\TwigCallableSourceIndexRegistry;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigArgumentParser;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Protocol\LspRequestFactory;
use Symfony\Lsp\Tests\Support\ProjectPaths;
use Symfony\Lsp\Tests\Support\ProviderRequests;

class TwigCallableProviderTestCase extends TestCase
{
    /**
     * @param array<string, string> $phpDocuments
     * @param array<string, string> $twigDocuments
     *
     * @return array{
     *     documents: DocumentStore,
     *     requests: ProviderRequests,
     *     converter: PositionConverter,
     *     protocol: LspProtocolMapper,
     *     completion: TwigCallableCompletionProvider,
     *     diagnostic: TwigCallableDiagnosticProvider,
     *     relationship: TwigCallableRelationshipProvider
     * }
     */
    protected function providers(array $phpDocuments, array $twigDocuments = []): array
    {
        $converter = new PositionConverter();
        $phpParser = new TolerantPhpParser(new Parser());
        $documents = new DocumentStore();
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $referenceExtractor = new TwigCallableReferenceExtractor(
            new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), $commentParser = new TwigCommentParser(), new TwigDirectiveLocator()),
            $converter,
            $directives = new TwigDirectiveLocator(),
            new TwigCallArgumentResolver(new TwigArgumentParser()),
        );
        $argumentAnalyzer = new TwigCallableArgumentAnalyzer(new TwigArgumentParser());
        $callableFacts = [];
        $classFacts = [];
        $declarationExtractor = new TwigCallableDeclarationExtractor($converter, $phpParser);
        $classExtractor = new PhpClassDeclarationExtractor($converter, $phpParser);
        foreach ($phpDocuments as $uri => $text) {
            $documents->open(new Document($uri, 'php', 1, $text));
            $callableFacts[] = $declarationExtractor->extract(new SourceDocument($uri, 'php', $text));
            $classFacts[] = new DependencyInjectionSourceFacts($uri, classes: $classExtractor->extract($uri, $text));
        }
        foreach ($twigDocuments as $uri => $text) {
            $documents->open(new Document($uri, 'twig', 1, $text));
            $callableFacts[] = $referenceExtractor->extract(new SourceDocument($uri, 'twig', $text));
        }
        $indexes = new TwigCallableSourceIndexRegistry();
        $indexes->forProject($project)->replace(...$callableFacts);
        $classIndexes = new DependencyInjectionSourceIndexRegistry();
        $classIndexes->forProject($project)->replace(...$classFacts);
        $documentResolver = new DocumentContextResolver($documents, $projects);
        $protocol = new LspProtocolMapper();
        $methodResolver = new TwigCallableMethodResolver(
            $classIndexes,
            new ProjectDocumentReader($documents, ProjectPaths::resolver()),
            $phpParser,
            $indexes,
        );

        return [
            'documents' => $documents,
            'requests' => new ProviderRequests($documents, $projects),
            'converter' => $converter,
            'protocol' => $protocol,
            'completion' => new TwigCallableCompletionProvider($converter, $protocol, $indexes, $methodResolver, $argumentAnalyzer, $commentParser, $directives),
            'diagnostic' => new TwigCallableDiagnosticProvider($documentResolver, $protocol, $indexes, $methodResolver),
            'relationship' => new TwigCallableRelationshipProvider(new LspRequestFactory($documents, $projects, $converter), $converter, $protocol, $indexes, $referenceExtractor, $methodResolver, $phpParser),
        ];
    }
}
