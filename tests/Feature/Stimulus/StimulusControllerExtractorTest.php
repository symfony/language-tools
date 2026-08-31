<?php

namespace Symfony\Lsp\Tests\Feature\Stimulus;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerDeclaration;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerExtractor;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\UriToPathConverter;

final class StimulusControllerExtractorTest extends TestCase
{
    public function testExtractsMembersFromAnUnclosedControllerClass(): void
    {
        $declaration = $this->extract(<<<'JS'
            export default class extends Controller {
                static targets = ['result'];

                open() {
                }
            JS);

        self::assertSame(
            [['result', 'target'], ['open', 'action']],
            array_map(static fn ($member): array => [$member->name, $member->kind->value], $declaration->members),
        );
    }

    public function testIgnoresBracesInStringsTemplatesAndCommentsWhenFindingTheClassBoundary(): void
    {
        $declaration = $this->extract(<<<'JS'
            export default class extends Controller {
                open() {
                    const string = "}";
                    const template = `<div>${value}</div> }`;
                    // }
                    /* } */
                }
            }

            class Helper {
                helper() {
                }
            }
            JS);

        self::assertSame(['open'], array_map(static fn ($member): string => $member->name, $declaration->members));
    }

    public function testReturnsADeclarationForAnIncompleteClassHeader(): void
    {
        $declaration = $this->extract('export default class extends Controller');

        self::assertSame([], $declaration->members);
    }

    private function extract(string $text): StimulusControllerDeclaration
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $extractor = new StimulusControllerExtractor(new PositionConverter(), new ProjectPathResolver(new UriToPathConverter()));

        return $extractor->extract($project, 'file:///workspace/assets/controllers/example_controller.js', $text)[0];
    }
}
