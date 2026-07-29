<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/tests')
    ->append([__FILE__, __DIR__.'/bin/symfony-lsp'])
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
