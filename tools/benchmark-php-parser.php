<?php

use Microsoft\PhpParser\Parser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;

require dirname(__DIR__).'/vendor/autoload.php';

$parser = new TolerantPhpParser(new Parser());
$fixtures = [
    'tests/Fixtures/Parser/php/valid.php.txt',
    'tests/Fixtures/Parser/php/incomplete.php.txt',
    'src/Server/LanguageServerFactory.php',
];
$iterations = 500;
printf("fixture,bytes,diagnostics,mean_ms,peak_bytes\n");
foreach ($fixtures as $path) {
    $source = file_get_contents(dirname(__DIR__).'/'.$path);
    if (false === $source) {
        throw new RuntimeException('Unable to read '.$path);
    }

    gc_collect_cycles();
    memory_reset_peak_usage();
    $startedAt = hrtime(true);
    $document = null;
    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        $document = $parser->parse($source);
    }
    $elapsed = hrtime(true) - $startedAt;
    printf(
        "%s,%d,%d,%.4f,%d\n",
        $path,
        strlen($source),
        count($document?->diagnostics() ?? []),
        $elapsed / 1_000_000 / $iterations,
        memory_get_peak_usage(true),
    );
}
