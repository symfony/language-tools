<?php

namespace Symfony\Lsp\Tests\Feature\Stimulus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Stimulus\JavaScriptSourceAnalyzer;
use Symfony\Lsp\Feature\Stimulus\StimulusCompletionContextResolver;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerExtractor;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerNameNormalizer;
use Symfony\Lsp\Feature\Stimulus\StimulusExtractor;
use Symfony\Lsp\Feature\Stimulus\StimulusReferenceExtractor;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigArgumentParser;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\UriToPathConverter;

final class StimulusExtractorTest extends TestCase
{
    #[DataProvider('lazyCommentProvider')]
    public function testDetectsLazyControllersFromCommentsAnywhereInTheFile(string $languageId, string $text, bool $expected): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $facts = $this->createExtractor()->extract($project, new SourceDocument('file:///workspace/assets/controllers/example_controller.js', $languageId, $text));

        self::assertSame($expected, $facts->declarations[0]->lazy);
    }

    public function testIgnoresMembersOutsideExportedControllerClass(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $facts = $this->createExtractor()->extract($project, new SourceDocument('file:///workspace/assets/controllers/example_controller.js', 'javascript', <<<'JS'
            class BeforeController {
                before() {
                }
            }

            export default class extends Controller {
                open() {
                }
            }

            class AfterController {
                after() {
                }
            }
            JS));

        self::assertSame(['open'], array_map(static fn ($member): string => $member->name, $facts->declarations[0]->members));
    }

    public function testIgnoresJavaScriptReferencesInsideCommentsAndStrings(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $facts = $this->createExtractor()->extract($project, new SourceDocument('file:///workspace/assets/controllers/example_controller.js', 'javascript', <<<'JS'
            const example = "application.register('string', Controller)";
            const template = `this.application.getControllerForElementAndIdentifier(element, 'template')`;
            // application.register('line-comment', Controller);
            /* this.application.getControllerForElementAndIdentifier(element, 'block-comment'); */
            application.register('registered', Controller);
            this.application.getControllerForElementAndIdentifier(element, 'resolved');
            JS));

        self::assertSame(
            ['registered', 'resolved'],
            array_map(static fn ($reference): string => $reference->controller, $facts->references),
        );
    }

    public function testDoesNotMaskTypeScriptDivisionAsRegularExpressions(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $facts = $this->createExtractor()->extract($project, new SourceDocument('file:///workspace/assets/controllers/example_controller.ts', 'typescript', <<<'TS'
            const ratio = value! / application.register('registered', Controller) / divisor;
            const genericRatio = factory<Type> / this.application.getControllerForElementAndIdentifier(element, 'resolved') / divisor;
            TS));

        self::assertSame(
            ['registered', 'resolved'],
            array_map(static fn ($reference): string => $reference->controller, $facts->references),
        );
    }

    public function testExtractsJavaScriptReferencesInsideTemplateInterpolations(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $facts = $this->createExtractor()->extract($project, new SourceDocument('file:///workspace/assets/controllers/example_controller.js', 'javascript', <<<'JS'
            const template = `
                application.register('template-text', Controller)
                ${application.register('registered', Controller)}
                ${this.application.getControllerForElementAndIdentifier(element, 'resolved')}
            `;
            JS));

        self::assertSame(
            ['registered', 'resolved'],
            array_map(static fn ($reference): string => $reference->controller, $facts->references),
        );
    }

    public function testIgnoresTwigReferencesInsideDocumentationComments(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $facts = $this->createExtractor()->extract($project, new SourceDocument('file:///workspace/templates/page.html.twig', 'twig', <<<'TWIG'
            {## Use stimulus_controller('documented') in examples. #}
            {{ stimulus_controller('real') }}
            TWIG));

        self::assertSame(['real'], array_map(static fn ($reference): string => $reference->controller, $facts->references));
    }

    public function testExtractsStaticTwigHelperCallsConservatively(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $facts = $this->createExtractor()->extract($project, new SourceDocument('file:///workspace/templates/page.html.twig', 'twig', <<<'TWIG'
            <div {{ stimulus_controller('chart').stimulus_action('chart', 'open') }}></div>
            {{ stimulus_target('chart', 'results') }}
            {{ stimulus_controller({dynamic: values}) }}
            {{ stimulus_action('chart') }}
            {{ stimulus_action('chart', name) }}
            {{ helpers.stimulus_controller('method') }}
            {% set snippet = "stimulus_controller('string')" %}
            {% verbatim %}{{ stimulus_controller('verbatim') }}{% endverbatim %}
            TWIG));

        self::assertSame(
            [
                ['chart', null, null],
                ['chart', null, null],
                ['chart', 'action', 'open'],
                ['chart', null, null],
                ['chart', 'target', 'results'],
            ],
            array_map(static fn ($reference): array => [$reference->controller, $reference->kind?->value, $reference->member], $facts->references),
        );
    }

    public function testDecodesEscapedTwigHelperArguments(): void
    {
        $project = new Project('/workspace', 'file:///workspace');
        $facts = $this->createExtractor()->extract($project, new SourceDocument('file:///workspace/templates/page.html.twig', 'twig', <<<'TWIG'
            {{ stimulus_controller('it\'s') }}
            {{ stimulus_action('it\'s', 'open\'s') }}
            {{ stimulus_target('it\'s', 'result\'s') }}
            TWIG));

        self::assertSame(
            [
                ["it's", null, null],
                ["it's", null, null],
                ["it's", 'action', "open's"],
                ["it's", null, null],
                ["it's", 'target', "result's"],
            ],
            array_map(static fn ($reference): array => [$reference->controller, $reference->kind?->value, $reference->member], $facts->references),
        );
    }

    private function createExtractor(): StimulusExtractor
    {
        $converter = new PositionConverter();
        $comments = new TwigCommentParser();
        $codeMasker = new JavaScriptSourceAnalyzer();
        $controllerNameNormalizer = new StimulusControllerNameNormalizer();

        return new StimulusExtractor(
            new StimulusControllerExtractor($converter, new ProjectPathResolver(new UriToPathConverter()), $codeMasker, $controllerNameNormalizer),
            new StimulusReferenceExtractor($converter, $comments, $codeMasker, $controllerNameNormalizer, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()), $comments), new TwigCallArgumentResolver(new TwigArgumentParser())),
            new StimulusCompletionContextResolver($converter, $comments, $controllerNameNormalizer),
        );
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function lazyCommentProvider(): iterable
    {
        yield 'single-quoted block comment' => ['javascript', "/* stimulusFetch: 'lazy' */\nexport default class extends Controller {}", true];
        yield 'double-quoted block comment' => ['javascript', "/* stimulusFetch: \"lazy\" */\nexport default class extends Controller {}", true];
        yield 'preserved block comment' => ['javascript', "/*! stimulusFetch: 'lazy' */\nexport default class extends Controller {}", true];
        yield 'single-quoted line comment' => ['javascript', "// stimulusFetch: 'lazy'\nexport default class extends Controller {}", true];
        yield 'double-quoted line comment' => ['javascript', "// stimulusFetch: \"lazy\"\nexport default class extends Controller {}", true];
        yield 'exported abstract TypeScript class' => ['typescript', "// stimulusFetch: 'lazy'\nexport default abstract class extends Controller {}", true];
        yield 'detached block comment' => ['javascript', "/* stimulusFetch: 'lazy' */\nconst mode = 'eager';\nexport default class extends Controller {}", true];
        yield 'comment inside the class' => ['javascript', "export default class extends Controller {\n    // stimulusFetch: 'lazy'\n}", true];
        yield 'controller without class' => ['javascript', "/* stimulusFetch: 'lazy' */\nexport default 'csrf-protection-controller'", true];
        yield 'incomplete declaration' => ['javascript', "// stimulusFetch: 'lazy'\nexport default", true];
        yield 'eager comment' => ['javascript', "/* stimulusFetch: 'eager' */\nexport default class extends Controller {}", false];
        yield 'no lazy marker' => ['javascript', 'export default class extends Controller {}', false];
    }
}
