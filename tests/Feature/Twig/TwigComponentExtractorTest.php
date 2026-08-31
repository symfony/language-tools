<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Twig\TemplateNameResolver;
use Symfony\Lsp\Feature\Twig\TwigComponentExtractor;
use Symfony\Lsp\Feature\Twig\TwigComponentNameResolver;
use Symfony\Lsp\Feature\Twig\TwigComponentPhpExtractor;
use Symfony\Lsp\Feature\Twig\TwigComponentTemplateExtractor;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigQuotedArgumentMatcher;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\UriToPathConverter;

final class TwigComponentExtractorTest extends TestCase
{
    public function testDecodesEscapedTwigComponentNames(): void
    {
        $facts = $this->extractor()->extract(
            new Project('/workspace', 'file:///workspace', '^8.0'),
            'file:///workspace/templates/page.html.twig',
            'twig',
            "{{ component('it\\'s') }}",
        );

        self::assertSame(["it's"], array_map(static fn ($reference): string => $reference->name, $facts->references));
    }

    public function testDecodesEscapedTwigLiveActionNames(): void
    {
        $facts = $this->extractor()->extract(
            new Project('/workspace', 'file:///workspace', '^8.0'),
            'file:///workspace/templates/components/Search.html.twig',
            'twig',
            "{{ live_action('it\\'s') }}",
        );

        self::assertSame(["it's"], array_map(static fn ($reference): string => $reference->action, $facts->actionReferences));
    }

    private function extractor(): TwigComponentExtractor
    {
        $converter = new PositionConverter();

        $names = new TwigComponentNameResolver(new TemplateNameResolver(new ProjectPathResolver(new UriToPathConverter())));

        return new TwigComponentExtractor(
            new TolerantPhpParser(new Parser()),
            new TwigComponentPhpExtractor($converter, $names),
            new TwigComponentTemplateExtractor($converter, $names, new TwigCommentParser(), new TwigQuotedArgumentMatcher($converter)),
        );
    }
}
