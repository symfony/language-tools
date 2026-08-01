<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\PhpAutowireReferenceExtractor;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;

final class PhpAutowireReferenceExtractorTest extends TestCase
{
    public function testExtractsAutowireReferencesAndApplicationClasses(): void
    {
        $text = <<<'PHP'
            <?php
            namespace App\Controller;

            use Symfony\Component\DependencyInjection\Attribute\Autowire as Inject;

            #[\App\Autowire(service: 'ignored')]
            final class DemoController
            {
                public function __construct(
                    #[Inject(service: 'app.mailer')]
                    object $mailer,
                    #[Inject(param: 'app.storage_dir')]
                    string $storage,
                    #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%app.api_key%')]
                    string $apiKey,
                ) {
                }
            }
            PHP;
        $converter = new PositionConverter();
        $references = (new PhpAutowireReferenceExtractor($converter, new TolerantPhpParser(new Parser())))->extract(
            'file:///workspace/src/Controller/DemoController.php',
            $text,
        );

        self::assertSame([
            ['service', 'app.mailer'],
            ['parameter', 'app.storage_dir'],
            ['parameter', 'app.api_key'],
        ], array_map(
            static fn ($reference): array => [$reference->kind()->value, $reference->name()],
            $references,
        ));
        self::assertSame(
            ['App\\Controller\\DemoController'],
            array_map(
                static fn ($declaration): string => $declaration->className(),
                (new PhpClassDeclarationExtractor($converter))->extract(
                    'file:///workspace/src/Controller/DemoController.php',
                    $text,
                ),
            ),
        );
    }
}
