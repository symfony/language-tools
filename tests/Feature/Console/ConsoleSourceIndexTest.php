<?php

namespace Symfony\Lsp\Tests\Feature\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Console\ConsoleCommandDeclaration;
use Symfony\Lsp\Feature\Console\ConsoleSourceFacts;
use Symfony\Lsp\Feature\Console\ConsoleSourceIndex;

final class ConsoleSourceIndexTest extends TestCase
{
    public function testReplacesOverlaysAndResolvesEffectiveDefinitions(): void
    {
        $index = new ConsoleSourceIndex();
        $index->replace(
            $this->facts('file:///workspace/src/SharedDefinition.php', new ConsoleCommandDeclaration('App\SharedDefinition', null, [], ['shared'], ['quiet'], false, true)),
            $this->facts('file:///workspace/src/BaseCommand.php', new ConsoleCommandDeclaration('App\BaseCommand', 'Symfony\Component\Console\Command\Command', ['App\SharedDefinition'], ['base'], [], false, true)),
            $this->facts('file:///workspace/src/ReportCommand.php', new ConsoleCommandDeclaration('App\ReportCommand', 'App\BaseCommand', [], ['report'], ['format'], false, true)),
        );

        $definition = $index->definition('App\ReportCommand');
        self::assertTrue($definition->command);
        self::assertTrue($definition->complete);
        self::assertSame(['base', 'report', 'shared'], $definition->arguments);
        self::assertSame(['format', 'quiet'], $definition->options);

        $index->overlay($this->facts('file:///workspace/src/ReportCommand.php', new ConsoleCommandDeclaration('App\ReportCommand', 'App\BaseCommand', [], ['overlay'], [], false, true)));
        self::assertSame(['base', 'overlay', 'shared'], $index->definition('App\ReportCommand')->arguments);

        $index->removeOverlay('file:///workspace/src/ReportCommand.php');
        self::assertSame(['base', 'report', 'shared'], $index->definition('App\ReportCommand')->arguments);

        $index->replaceSource($this->facts('file:///workspace/src/ReportCommand.php', new ConsoleCommandDeclaration('App\ReportCommand', 'App\MissingBaseCommand', [], ['report'], [], false, true)));
        self::assertFalse($index->definition('App\ReportCommand')->complete);
    }

    private function facts(string $uri, ConsoleCommandDeclaration $declaration): ConsoleSourceFacts
    {
        return new ConsoleSourceFacts($uri, [$declaration], []);
    }
}
