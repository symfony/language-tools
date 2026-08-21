<?php

namespace Symfony\Lsp\Tests\Parser\Php;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Php\LastResultPhpParser;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class LastResultPhpParserTest extends TestCase
{
    public function testReusesOnlyTheLastSourceResult(): void
    {
        $inner = new RecordingPhpParser();
        $parser = new LastResultPhpParser($inner);

        $first = $parser->parse('<?php first();');
        self::assertSame($first, $parser->parse('<?php first();'));
        $second = $parser->parse('<?php second();');
        self::assertNotSame($first, $second);
        self::assertNotSame($first, $parser->parse('<?php first();'));
        self::assertSame(['<?php first();', '<?php second();', '<?php first();'], $inner->sources);
    }
}

final class RecordingPhpParser implements PhpParserInterface
{
    /** @var list<string> */
    public array $sources = [];

    public function parse(string $source): PhpDocument
    {
        $this->sources[] = $source;

        return new PhpDocument([], [], [], []);
    }
}
