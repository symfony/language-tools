<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolCompletionKind;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolExtractor;
use Symfony\Lsp\Feature\Twig\TwigPhpSymbolKind;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TwigPhpSymbolExtractorTest extends TestCase
{
    public function testExtractsPhpDeclarationsAndTwigReferences(): void
    {
        $extractor = $this->extractor($converter = new PositionConverter());
        $php = <<<'PHP'
            <?php
            namespace App\Model;

            enum Status: string
            {
                case Draft = 'draft';
                case Published = 'published';
                public const LABEL = 'Status';
            }

            final class ViewOptions
            {
                public const FORMAT = 'html';
                private const SECRET = 'secret';
            }

            trait SharedOptions
            {
                public const IGNORED = 'ignored';
            }
            PHP;
        $twig = <<<'TWIG'
            é {{ constant('App\\Model\\ViewOptions::FORMAT') }}
            {{ constant("App\\Model\\Status::Published") }}
            {{ enum('App\\Model\\Status').Published }}
            {% for status in enum_cases('App\\Model\\Status') %}
            {% endfor %}
            {{ enum(enum: 'App\\Model\\Status').Draft.name }}
            {# {{ constant('App\\Model\\ViewOptions::SECRET') }} #}
            {% verbatim %}{{ enum('App\\Model\\Status').Draft }}{% endverbatim %}
            {{ constant(dynamic_name) }}
            TWIG;

        $phpFacts = $extractor->extract('file:///workspace/src/Model.php', 'php', $php);
        self::assertNotNull($phpFacts);
        self::assertSame([
            [TwigPhpSymbolKind::Enum, 'App\Model\Status', null, true],
            [TwigPhpSymbolKind::Class_, 'App\Model\ViewOptions', null, true],
            [TwigPhpSymbolKind::EnumCase, 'App\Model\Status', 'Draft', true],
            [TwigPhpSymbolKind::EnumCase, 'App\Model\Status', 'Published', true],
            [TwigPhpSymbolKind::ClassConstant, 'App\Model\Status', 'LABEL', true],
            [TwigPhpSymbolKind::ClassConstant, 'App\Model\ViewOptions', 'FORMAT', true],
            [TwigPhpSymbolKind::ClassConstant, 'App\Model\ViewOptions', 'SECRET', false],
        ], array_map(static fn ($declaration): array => [
            $declaration->kind,
            $declaration->className,
            $declaration->memberName,
            $declaration->public,
        ], $phpFacts->declarations));

        $twigFacts = $extractor->extract('file:///workspace/templates/page.html.twig', 'twig', $twig);
        self::assertNotNull($twigFacts);
        self::assertSame([
            ['App\Model\ViewOptions', null],
            ['App\Model\ViewOptions', 'FORMAT'],
            ['App\Model\Status', null],
            ['App\Model\Status', 'Published'],
            ['App\Model\Status', null],
            ['App\Model\Status', 'Published'],
            ['App\Model\Status', null],
            ['App\Model\Status', null],
            ['App\Model\Status', 'Draft'],
        ], array_map(static fn ($reference): array => [$reference->className, $reference->memberName], $twigFacts->references));
        self::assertSame(15, $twigFacts->references[0]->range->start->character);
        foreach ($twigFacts->references as $reference) {
            $start = $converter->toByteOffset($twig, $reference->range->start);
            $end = $converter->toByteOffset($twig, $reference->range->end);
            self::assertNotSame('', substr($twig, $start, $end - $start));
        }
    }

    public function testFindsCompletionContexts(): void
    {
        $extractor = $this->extractor($converter = new PositionConverter());

        $constantType = "{{ constant('App\\\\Mod";
        $context = $extractor->completionContext($constantType, \strlen($constantType));
        self::assertNotNull($context);
        self::assertSame(TwigPhpSymbolCompletionKind::ConstantType, $context->kind);
        self::assertSame('App\Mod', $context->prefix);

        $constantMember = "{{ constant('App\\\\Model\\\\ViewOptions::FO";
        $context = $extractor->completionContext($constantMember, \strlen($constantMember));
        self::assertNotNull($context);
        self::assertSame(TwigPhpSymbolCompletionKind::ConstantMember, $context->kind);
        self::assertSame('App\Model\ViewOptions', $context->className);
        self::assertSame('FO', $context->prefix);

        $enumType = "{{ enum_cases('App\\\\Model\\\\St";
        $context = $extractor->completionContext($enumType, \strlen($enumType));
        self::assertNotNull($context);
        self::assertSame(TwigPhpSymbolCompletionKind::EnumType, $context->kind);
        self::assertSame('App\Model\St', $context->prefix);

        $enumCase = "{{ enum('App\\\\Model\\\\Status').Pu";
        $context = $extractor->completionContext($enumCase, \strlen($enumCase));
        self::assertNotNull($context);
        self::assertSame(TwigPhpSymbolCompletionKind::EnumCase, $context->kind);
        self::assertSame('App\Model\Status', $context->className);
        self::assertSame('Pu', $context->prefix);

        $comment = "{# {{ enum('App\\\\Model\\\\Status').Pu";
        self::assertNull($extractor->completionContext($comment, \strlen($comment)));
        self::assertNull($extractor->completionContext('Plain constant(\'App', \strlen('Plain constant(\'App')));
    }

    private function extractor(PositionConverter $converter): TwigPhpSymbolExtractor
    {
        $comments = new TwigCommentParser();

        return new TwigPhpSymbolExtractor(
            $converter,
            new TolerantPhpParser(new Parser()),
            new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), $comments),
            $comments,
            new TwigDirectiveLocator(),
        );
    }
}
