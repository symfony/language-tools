<?php

namespace Symfony\Lsp\Tests\Feature\Stimulus;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Stimulus\StimulusControllerDeclaration;
use Symfony\Lsp\Feature\Stimulus\StimulusMemberKind;
use Symfony\Lsp\Feature\Stimulus\StimulusReference;
use Symfony\Lsp\Feature\Stimulus\StimulusSourceFacts;
use Symfony\Lsp\Feature\Stimulus\StimulusSourceIndex;

final class StimulusSourceIndexTest extends TestCase
{
    public function testInvalidatesCachedDeclarationsAndReferences(): void
    {
        $index = new StimulusSourceIndex();
        [$firstFacts, $firstDeclaration, $firstControllerReference, $firstActionReference] = $this->facts('first');
        $index->replace($firstFacts);

        self::assertSame([$firstDeclaration], $index->declarations('first'));
        self::assertSame([$firstControllerReference], $index->references('first'));
        self::assertSame([$firstActionReference], $index->references('first', StimulusMemberKind::Action, 'open'));

        [$secondFacts, $secondDeclaration, $secondControllerReference, $secondActionReference] = $this->facts('second');
        $index->replaceSource($secondFacts);

        self::assertSame([$secondDeclaration], $index->declarations());
        self::assertSame([], $index->declarations('first'));
        self::assertSame([$secondDeclaration], $index->declarations('second'));
        self::assertSame([], $index->references('first'));
        self::assertSame([$secondControllerReference], $index->references('second'));
        self::assertSame([$secondActionReference], $index->references('second', StimulusMemberKind::Action, 'open'));
    }

    /** @return array{StimulusSourceFacts, StimulusControllerDeclaration, StimulusReference, StimulusReference} */
    private function facts(string $name): array
    {
        $uri = 'file:///assets/controllers/controller.js';
        $declaration = new StimulusControllerDeclaration($name, $uri, $this->range(), [], false);
        $controllerReference = new StimulusReference($name, null, null, $uri, $this->range());
        $actionReference = new StimulusReference($name, StimulusMemberKind::Action, 'open', $uri, $this->range());

        return [new StimulusSourceFacts($uri, [$declaration], [$controllerReference, $actionReference]), $declaration, $controllerReference, $actionReference];
    }

    private function range(): Range
    {
        return new Range(new Position(0, 0), new Position(0, 1));
    }
}
