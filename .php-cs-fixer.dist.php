<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__.'/bin')
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/tests')
    ->in(__DIR__.'/tools')
    ->exclude('Fixtures/RuntimeApplication')
    ->append([__FILE__, __DIR__.'/resources/services.php'])
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
