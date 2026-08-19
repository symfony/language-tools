<?php

namespace Symfony\Lsp\Tests\Feature\Stimulus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Stimulus\StimulusExtractor;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\UriToPathConverter;

final class StimulusExtractorTest extends TestCase
{
    #[DataProvider('lazyCommentProvider')]
    public function testDetectsLazyControllersOnlyWhenTheCommentIsAttachedToTheClass(string $languageId, string $text, bool $expected): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $extractor = new StimulusExtractor(new PositionConverter(), new ProjectPathResolver(new UriToPathConverter()), new TwigCommentParser());
        $facts = $extractor->extract($project, 'file:///workspace/assets/controllers/example_controller.js', $languageId, $text);

        self::assertSame($expected, $facts->declarations()[0]->isLazy());
    }

    public function testIgnoresTwigReferencesInsideDocumentationComments(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $extractor = new StimulusExtractor(new PositionConverter(), new ProjectPathResolver(new UriToPathConverter()), new TwigCommentParser());
        $facts = $extractor->extract($project, 'file:///workspace/templates/page.html.twig', 'twig', <<<'TWIG'
            {## Use stimulus_controller('documented') in examples. #}
            {{ stimulus_controller('real') }}
            TWIG);

        self::assertSame(['real'], array_map(static fn ($reference): string => $reference->controller(), $facts->references()));
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
        yield 'detached block comment' => ['javascript', "/* stimulusFetch: 'lazy' */\nconst mode = 'eager';\nexport default class extends Controller {}", false];
        yield 'comment inside the class' => ['javascript', "export default class extends Controller {\n    // stimulusFetch: 'lazy'\n}", false];
        yield 'eager comment' => ['javascript', "/* stimulusFetch: 'eager' */\nexport default class extends Controller {}", false];
        yield 'incomplete declaration' => ['javascript', "// stimulusFetch: 'lazy'\nexport default", false];
    }
}
