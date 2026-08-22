<?php

namespace Symfony\Lsp\Tests\Parser;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\QuotedArgumentMatcher;

final class QuotedArgumentMatcherTest extends TestCase
{
    private QuotedArgumentMatcher $matcher;
    private PositionConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new PositionConverter();
        $this->matcher = new QuotedArgumentMatcher($this->converter);
    }

    public function testMatchesMethodAndStaticCallsWithBothQuoteStyles(): void
    {
        $text = "<?php \$router->generate('homepage'); Router::generate(\"admin\");";

        $arguments = $this->matcher->methodCalls($text, ['generate']);

        self::assertCount(2, $arguments);
        self::assertSame('homepage', $arguments[0]->value);
        self::assertSame('generate', $arguments[0]->name);
        self::assertSame('admin', $arguments[1]->value);
        self::assertSame(strpos($text, 'homepage'), $arguments[0]->offset);
        self::assertSame(\strlen('homepage'), $arguments[0]->length);
    }

    public function testMatchesFunctionCallsIncludingMethodStyleReceivers(): void
    {
        $text = "{{ asset('build/app.js') }} {{ this.asset('other.js') }}";

        $arguments = $this->matcher->functionCalls($text, ['asset']);

        self::assertSame(['build/app.js', 'other.js'], array_map(
            static fn ($argument): string => $argument->value,
            $arguments,
        ));
    }

    public function testDecodesEscapedQuotesWhileRangesCoverTheRawSpan(): void
    {
        $text = "<?php \$t->trans('it\\'s here'); \$t->trans(\"say \\\"hi\\\"\");";

        $arguments = $this->matcher->methodCalls($text, ['trans']);

        self::assertCount(2, $arguments);
        self::assertSame("it's here", $arguments[0]->value);
        self::assertSame(\strlen("it\\'s here"), $arguments[0]->length);
        self::assertSame('say "hi"', $arguments[1]->value);
        $start = $this->converter->toByteOffset($text, $arguments[0]->range->start());
        $end = $this->converter->toByteOffset($text, $arguments[0]->range->end());
        self::assertSame($arguments[0]->offset, $start);
        self::assertSame($arguments[0]->offset + $arguments[0]->length, $end);
    }

    public function testKeepsUnknownSingleQuoteEscapesLiteral(): void
    {
        $text = "<?php \$t->trans('path\\\\to\\np');";

        $arguments = $this->matcher->methodCalls($text, ['trans']);

        self::assertSame('path\\to\\np', $arguments[0]->value ?? null);
    }

    public function testNeverMatchesDynamicDoubleQuotedStrings(): void
    {
        $text = '<?php $t->trans("user.$name"); $t->trans("line\n");';

        self::assertSame([], $this->matcher->methodCalls($text, ['trans']));
    }

    public function testNeverMatchesEmptyLiteralsOrOtherNames(): void
    {
        $text = "<?php \$t->trans(''); \$t->translate('key');";

        self::assertSame([], $this->matcher->methodCalls($text, ['trans']));
    }

    public function testReportsMultibyteRangesInNegotiatedEncoding(): void
    {
        $text = "{{ 'héllo 🚀' }}{{ trans('clé.été') }}";

        $arguments = $this->matcher->functionCalls($text, ['trans']);

        self::assertCount(1, $arguments);
        self::assertSame('clé.été', $arguments[0]->value);
        self::assertSame((int) strpos($text, 'clé.été'), $arguments[0]->offset);
        $start = $this->converter->toByteOffset($text, $arguments[0]->range->start());
        self::assertSame($arguments[0]->offset, $start);
    }

    public function testReportsTheEndOffsetAfterTheClosingQuote(): void
    {
        $text = "<?php \$c->render('index.html.twig', ['user' => \$user]);";

        $arguments = $this->matcher->methodCalls($text, ['render']);

        self::assertCount(1, $arguments);
        self::assertSame(',', $text[$arguments[0]->end()]);
        self::assertSame(strpos($text, 'render'), $arguments[0]->nameOffset);
    }
}
