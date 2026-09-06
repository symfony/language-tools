#!/usr/bin/env php
<?php

use Symfony\Component\DependencyInjection\Reference;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Feature\DiagnosticCollector;
use Symfony\Lsp\Feature\Route\Route;
use Symfony\Lsp\Feature\Route\RouteDiagnosticPublisher;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteSourceFacts;
use Symfony\Lsp\Feature\Route\RouteSourceIndexRegistry;
use Symfony\Lsp\Feature\Twig\TemplateIndexRegistry;
use Symfony\Lsp\Feature\Twig\TemplateNavigationProvider;
use Symfony\Lsp\Feature\Twig\TwigCallableDiagnosticProvider;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Index\SourceFileEnumerator;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Tools\CountingDiagnosticPhpParser;
use Symfony\Lsp\Tools\DiagnosticParseCounter;

use function Symfony\Lsp\Tools\createBenchmarkContainer;
use function Symfony\Lsp\Tools\installBenchmarkSyntheticServices;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/benchmark-container.php';

if (!function_exists('symfony_lsp_tree_sitter_parse')) {
    fwrite(\STDERR, "The Tree-sitter extension is not loaded. Run through: composer diagnostics:benchmark\n");
    exit(1);
}

$projectRoot = realpath($argv[1] ?? dirname(__DIR__).'/var/build/source-index-benchmark/1500');
if (false === $projectRoot || !is_dir($projectRoot)) {
    fwrite(\STDERR, "The benchmark project is unavailable. Run composer source-index:benchmark or pass a generated project directory.\n");
    exit(1);
}
foreach (['src/Twig', 'templates'] as $directory) {
    if (!is_dir($projectRoot.'/'.$directory)) {
        mkdir($projectRoot.'/'.$directory, 0777, true);
    }
}
file_put_contents($projectRoot.'/src/Twig/BenchmarkExtension.php', <<<'PHP'
    <?php

    namespace App\Twig;

    use Twig\Attribute\AsTwigFunction;

    final class BenchmarkExtension
    {
        #[AsTwigFunction('benchmark')]
        public function benchmark(string $name, int $width = 100): string
        {
            return $name;
        }
    }
    PHP);
$cases = [
    'templates/benchmark-callable.html.twig' => ["{{ benchmark(name: 'value', typo: 1) }}\n", ['twig_callable.unknown_argument']],
    'templates/benchmark-route.html.twig' => ["{{ path('benchmark_missing_route') }}\n", ['route.not_found']],
    'templates/benchmark-template.html.twig' => ["{% include 'benchmark_missing_template.html.twig' %}\n", ['template.not_found']],
];
foreach ($cases as $path => [$text]) {
    file_put_contents($projectRoot.'/'.$path, $text);
}

$container = createBenchmarkContainer('diagnostic-benchmark');
$container->register(DiagnosticParseCounter::class)->setSynthetic(true)->setPublic(true);
$container->register(CountingDiagnosticPhpParser::class, CountingDiagnosticPhpParser::class)
    ->setDecoratedService(TolerantPhpParser::class)
    ->setArgument('$parser', new Reference(CountingDiagnosticPhpParser::class.'.inner'))
    ->setArgument('$counter', new Reference(DiagnosticParseCounter::class));
$providers = [
    'route' => new Reference(RouteDiagnosticPublisher::class),
    'template' => new Reference(TemplateNavigationProvider::class),
    'twig_callable' => new Reference(TwigCallableDiagnosticProvider::class),
];
$container->getDefinition(DiagnosticCollector::class)->setArgument('$providers', array_values($providers));
foreach ([
    ApplicationSourceScanner::class,
    DiagnosticCollector::class,
    DocumentStore::class,
    ProjectRegistry::class,
    RouteIndexRegistry::class,
    RouteSourceIndexRegistry::class,
    SourceFileEnumerator::class,
    TemplateIndexRegistry::class,
] as $service) {
    $container->getDefinition($service)->setPublic(true);
}
$container->getAlias(PhpParserInterface::class)->setPublic(true);
$container->compile();
installBenchmarkSyntheticServices($container);
$counter = new DiagnosticParseCounter();
$container->set(DiagnosticParseCounter::class, $counter);

$uris = new UriToPathConverter();
$project = new Project($projectRoot, $uris->toUri($projectRoot));
/** @var ProjectRegistry $projectRegistry */
$projectRegistry = $container->get(ProjectRegistry::class);
$projectRegistry->replace([$project]);
/** @var ApplicationSourceScanner $sourceScanner */
$sourceScanner = $container->get(ApplicationSourceScanner::class);
$sourceScanner->refreshProject($project);

/** @var RouteSourceIndexRegistry $routeSources */
$routeSources = $container->get(RouteSourceIndexRegistry::class);
/** @var SourceFileEnumerator $files */
$files = $container->get(SourceFileEnumerator::class);
$documents = [];
$routes = [];
foreach ($files->files($project) as $path) {
    $languageId = $files->languageId($path);
    $relativePath = $files->relativePath($project, $path);
    if (null === $languageId || null === $relativePath) {
        continue;
    }
    $uri = $uris->toUri($path);
    $documents[] = [$uri, $languageId, $path, $relativePath];
    $facts = $routeSources->forProject($project)->factsForUri($uri);
    foreach ($facts instanceof RouteSourceFacts ? $facts->declarations : [] as $declaration) {
        $routes[$declaration->name] = new Route($declaration->name, '/benchmark', [], [], null, null);
    }
}
/** @var RouteIndexRegistry $routeIndexes */
$routeIndexes = $container->get(RouteIndexRegistry::class);
$routeIndexes->forProject($project)->replace(...array_values($routes));
/** @var TemplateIndexRegistry $templateIndexes */
$templateIndexes = $container->get(TemplateIndexRegistry::class);
$templateIndex = $templateIndexes->forProject($project);
$templateIndex->replaceRuntime(true, ...$templateIndex->matching(''));

/** @var PhpParserInterface $phpParser */
$phpParser = $container->get(PhpParserInterface::class);
$phpParser->parse('<?php final class DiagnosticCacheEviction {}');
$counter->reset();
/** @var DocumentStore $documentStore */
$documentStore = $container->get(DocumentStore::class);
/** @var DiagnosticCollector $collector */
$collector = $container->get(DiagnosticCollector::class);
$diagnosticCount = 0;
$failureCount = 0;
$observed = [];
$startedAt = hrtime(true);
foreach ($documents as [$uri, $languageId, $path, $relativePath]) {
    $text = file_get_contents($path);
    if (false === $text) {
        throw new RuntimeException('Unable to read the diagnostic benchmark source.');
    }
    $documentStore->open(new Document($uri, $languageId, 0, $text));
    $collection = $collector->collectDetailed(['textDocument' => ['uri' => $uri]]);
    $diagnosticCount += null === $collection ? 0 : count($collection->diagnostics);
    $failureCount += null === $collection ? 0 : count($collection->failures);
    if (isset($cases[$relativePath])) {
        $observed[$relativePath] = [];
        foreach ($collection->diagnostics ?? [] as $diagnostic) {
            $observed[$relativePath][] = $diagnostic->diagnostic['code'] ?? null;
        }
    }
    $documentStore->close($uri);
}
$milliseconds = (hrtime(true) - $startedAt) / 1_000_000;
$expected = [];
foreach ($cases as $path => [, $codes]) {
    $expected[$path] = $codes;
}
ksort($expected);
ksort($observed);

$result = [
    'coveredProviders' => array_keys($providers),
    'files' => count($documents),
    'diagnostics' => $diagnosticCount,
    'fixtureDiagnostics' => $observed,
    'milliseconds' => round($milliseconds, 1),
    'phpParseCallsAfterIndexing' => $counter->calls,
    'phpParsedBytesAfterIndexing' => $counter->bytes,
    'targets' => [
        'expectedFixtureDiagnostics' => $observed === $expected,
        'noProviderFailures' => 0 === $failureCount,
        'diagnosticsUnderOneSecond' => $milliseconds < 1000,
        'noRepeatedPhpParsing' => 0 === $counter->calls,
    ],
];

echo json_encode($result, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n";
exit(in_array(false, $result['targets'], true) ? 1 : 0);
