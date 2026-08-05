<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/tests')
    ->in(__DIR__.'/tools')
    ->exclude('Fixtures/RuntimeApplication')
    ->append([
        __FILE__,
        __DIR__.'/bin/symfony-lsp',
        __DIR__.'/tools/benchmark-server',
        __DIR__.'/tools/dogfood-server',
        __DIR__.'/tools/dogfood-symfonycorp',
        __DIR__.'/tools/release',
        __DIR__.'/tools/smoke-test-server',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'header_comment' => false,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
;
