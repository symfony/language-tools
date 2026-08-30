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
                (new PhpClassDeclarationExtractor($converter, new TolerantPhpParser(new Parser())))->extract(
                    'file:///workspace/src/Controller/DemoController.php',
                    $text,
                ),
            ),
        );
    }

    public function testDecodesEscapedServiceIdsWithSourceRanges(): void
    {
        $text = <<<'PHP'
            <?php
            use Symfony\Component\DependencyInjection\Attribute\Autowire;

            final class Mailer
            {
                public function __construct(
                    #[Autowire(service: 'App\\Transport\\Smtp')]
                    object $transport,
                ) {
                }
            }
            PHP;

        $references = (new PhpAutowireReferenceExtractor(new PositionConverter(), new TolerantPhpParser(new Parser())))->extract(
            'file:///workspace/src/Mailer.php',
            $text,
        );

        self::assertCount(1, $references);
        self::assertSame('App\Transport\Smtp', $references[0]->name());
        $start = $references[0]->range()->start;
        $end = $references[0]->range()->end;
        self::assertSame("'App\\\\Transport\\\\Smtp'", substr(
            explode("\n", $text)[$start->line],
            $start->character - 1,
            $end->character - $start->character + 2,
        ));
    }
}
