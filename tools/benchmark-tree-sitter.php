<?php

use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;

require dirname(__DIR__).'/vendor/autoload.php';

$parser = new NativeTreeSitterParser(new TreeSitterResultDecoder());
$fixtures = [
    ['twig', 'tests/Fixtures/Parser/twig/valid.html.twig'],
    ['twig', 'tests/Fixtures/Parser/twig/incomplete.html.twig'],
    ['yaml', 'tests/Fixtures/Parser/yaml/valid.yaml'],
    ['yaml', 'tests/Fixtures/Parser/yaml/incomplete.yaml'],
];
$iterations = 1000;
printf("fixture,bytes,errors,mean_ms,peak_bytes\n");
foreach ($fixtures as [$language, $path]) {
    $source = file_get_contents(dirname(__DIR__).'/'.$path);
    if (false === $source) {
        throw new RuntimeException('unable to read '.$path);
    }
    $startedAt = hrtime(true);
    $tree = null;
    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        $tree = $parser->parse($language, $source);
    }
    $elapsed = hrtime(true) - $startedAt;
    printf(
        "%s,%d,%s,%.4f,%d\n",
        $path,
        strlen($source),
        $tree?->hasError() ? 'yes' : 'no',
        $elapsed / 1_000_000 / $iterations,
        memory_get_peak_usage(true),
    );
}
