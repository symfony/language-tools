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
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigArgumentParser;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\UriToPathConverter;

final class TwigComponentExtractorTest extends TestCase
{
    public function testExtractsStaticTwigHelperArgumentsConservatively(): void
    {
        $facts = $this->extractor()->extract(
            new Project('/workspace', 'file:///workspace'),
            new SourceDocument('file:///workspace/templates/components/Search.html.twig',
                'twig',
                <<<'TWIG'
                {# {{ component(name: 'Commented') }} #}
                {# {{ live_action(actionName: 'commented') }} #}
                {{ component('Card', {title: title}) }}
                {{ component(props: {}, name: 'Alert') }}
                {{ component(name = "Banner") }}
                {{ component(name: dynamic_name) }}
                {{ component(name: 'Prefix' ~ suffix) }}
                {{ live_action('save', {id: id}) }}
                {{ live_action(args: {}, actionName: 'submit') }}
                {{ live_action(actionName = "reset") }}
                {{ live_action(actionName: dynamic_action) }}
                {{ live_action(actionName: 'prefix-' ~ suffix) }}
                TWIG),
        );

        self::assertSame(
            ['Card', 'Alert', 'Banner'],
            array_map(static fn ($reference): string => $reference->name, $facts->references),
        );
        self::assertSame(
            ['save', 'submit', 'reset'],
            array_map(static fn ($reference): string => $reference->action, $facts->actionReferences),
        );
    }

    public function testExtractsComponentMarkupOnlyFromRenderedMarkup(): void
    {
        $facts = $this->extractor()->extract(
            new Project('/workspace', 'file:///workspace'),
            new SourceDocument('file:///workspace/templates/page.html.twig',
                'twig',
                <<<'TWIG'
                {% if enabled %}<twig:Alert data-live-action-param="save" />{% endif %}
                {# <twig:Commented data-live-action-param="commented" /> #}
                {{ '<twig:Stringy data-live-action-param="stringy" />' }}
                {% set markup = "<twig:Coded data-live-action-param='coded' />" %}
                {% verbatim %}<twig:Verbatim data-live-action-param="verbatim" />{% endverbatim %}
                <twig:Dynamic data-live-action-param="{{ action }}" />
                TWIG),
        );

        self::assertSame(
            ['Alert', 'Dynamic'],
            array_map(static fn ($reference): string => $reference->name, $facts->references),
        );
        self::assertSame(
            [['Alert', 'save']],
            array_map(static fn ($reference): array => [$reference->component, $reference->action], $facts->actionReferences),
        );
    }

    public function testDecodesEscapedTwigComponentNames(): void
    {
        $facts = $this->extractor()->extract(
            new Project('/workspace', 'file:///workspace'),
            new SourceDocument('file:///workspace/templates/page.html.twig',
                'twig',
                "{{ component('it\\'s') }}"),
        );

        self::assertSame(["it's"], array_map(static fn ($reference): string => $reference->name, $facts->references));
    }

    public function testDecodesEscapedTwigLiveActionNames(): void
    {
        $facts = $this->extractor()->extract(
            new Project('/workspace', 'file:///workspace'),
            new SourceDocument('file:///workspace/templates/components/Search.html.twig',
                'twig',
                "{{ live_action('it\\'s') }}"),
        );

        self::assertSame(["it's"], array_map(static fn ($reference): string => $reference->action, $facts->actionReferences));
    }

    private function extractor(): TwigComponentExtractor
    {
        $converter = new PositionConverter();

        $names = new TwigComponentNameResolver(new TemplateNameResolver(new ProjectPathResolver(new UriToPathConverter())));
        $comments = new TwigCommentParser();

        return new TwigComponentExtractor(
            new TolerantPhpParser(new Parser()),
            new TwigComponentPhpExtractor($converter, $names),
            new TwigComponentTemplateExtractor(
                $converter,
                $names,
                new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), $comments),
                new TwigCallArgumentResolver(new TwigArgumentParser()),
            ),
        );
    }
}
