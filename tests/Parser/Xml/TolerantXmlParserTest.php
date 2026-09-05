<?php

namespace Symfony\Lsp\Tests\Parser\Xml;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Xml\TolerantXmlParser;
use Symfony\Lsp\Parser\Xml\XmlElementEnd;
use Symfony\Lsp\Parser\Xml\XmlElementStart;
use Symfony\Lsp\Parser\Xml\XmlOpaque;
use Symfony\Lsp\Parser\Xml\XmlOpaqueKind;
use Symfony\Lsp\Parser\Xml\XmlText;
use Symfony\Lsp\Parser\Xml\XmlTextKind;

final class TolerantXmlParserTest extends TestCase
{
    public function testExposesFlatEventsWithExactRangesAndParentIdentities(): void
    {
        $source = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE root [
                <!ENTITY external SYSTEM "file:///etc/passwd">
                <!ENTITY declared "<phantom/>">
            ]>
            <root threshold="1 > 0" exact:name='value' marker="<!-- literal -->">
                raw &declared;
                <![CDATA[<phantom id="cdata"/> &amp;]]>
                <!-- <phantom id="comment"/> -->
                <?work <phantom id="pi"/>?>
                <child id="real"><nested/>tail</child>
            </root>
            XML;

        $document = (new TolerantXmlParser())->parse($source);
        $starts = array_values(array_filter($document->events, static fn ($event): bool => $event instanceof XmlElementStart));
        $ends = array_values(array_filter($document->events, static fn ($event): bool => $event instanceof XmlElementEnd));
        $texts = array_values(array_filter($document->events, static fn ($event): bool => $event instanceof XmlText));
        $opaque = array_values(array_filter($document->events, static fn ($event): bool => $event instanceof XmlOpaque));

        self::assertSame(['root', 'child', 'nested'], array_map(static fn (XmlElementStart $event): string => $event->qualifiedName, $starts));
        self::assertSame([null, $starts[0]->identity, $starts[1]->identity], array_map(static fn (XmlElementStart $event): ?int => $event->parentIdentity, $starts));
        self::assertSame(['child', 'root'], array_map(static fn (XmlElementEnd $event): string => $event->qualifiedName, $ends));
        self::assertSame($starts[1]->identity, $ends[0]->identity);
        self::assertSame($starts[0]->identity, $ends[1]->identity);

        self::assertSame(['threshold', 'exact:name', 'marker'], array_map(static fn ($attribute): string => $attribute->qualifiedName, $starts[0]->attributes));
        self::assertSame(['1 > 0', 'value', '<!-- literal -->'], array_map(static fn ($attribute): string => $attribute->value, $starts[0]->attributes));
        foreach ($starts[0]->attributes as $attribute) {
            self::assertSame($attribute->qualifiedName, substr($source, $attribute->nameStartOffset, $attribute->nameEndOffset - $attribute->nameStartOffset));
            self::assertSame($attribute->value, substr($source, $attribute->valueStartOffset, $attribute->valueEndOffset - $attribute->valueStartOffset));
        }

        self::assertSame([XmlOpaqueKind::ProcessingInstruction, XmlOpaqueKind::Doctype, XmlOpaqueKind::Comment, XmlOpaqueKind::ProcessingInstruction], array_map(static fn (XmlOpaque $event): XmlOpaqueKind => $event->kind, $opaque));
        self::assertStringContainsString('<!ENTITY declared "<phantom/>">', substr($source, $opaque[1]->startOffset, $opaque[1]->endOffset - $opaque[1]->startOffset));

        $cdata = array_values(array_filter($texts, static fn (XmlText $text): bool => XmlTextKind::Cdata === $text->kind));
        self::assertCount(1, $cdata);
        self::assertSame('<phantom id="cdata"/> &amp;', $cdata[0]->raw);
        self::assertSame($cdata[0]->raw, substr($source, $cdata[0]->startOffset, $cdata[0]->endOffset - $cdata[0]->startOffset));
        self::assertSame($starts[0]->identity, $cdata[0]->parentIdentity);
        self::assertSame([], $document->diagnostics);
    }

    public function testRecoversAtMarkupAfterAnUnterminatedAttribute(): void
    {
        $source = "<root><broken value=\"unfinished\n<sibling id=\"ok\"/></root>";
        $document = (new TolerantXmlParser())->parse($source);
        $starts = array_values(array_filter($document->events, static fn ($event): bool => $event instanceof XmlElementStart));

        self::assertSame(['root', 'sibling'], array_map(static fn (XmlElementStart $event): string => $event->qualifiedName, $starts));
        self::assertSame($starts[0]->identity, $starts[1]->parentIdentity);
        self::assertSame('ok', $starts[1]->attribute('id')?->value);
        self::assertSame('Opening element "broken" is not closed.', $document->diagnostics[0]->message);
    }

    #[DataProvider('longOpaqueConstructProvider')]
    public function testKeepsLongOpaqueConstructsOpaque(string $source): void
    {
        $document = (new TolerantXmlParser())->parse($source);
        $starts = array_values(array_filter($document->events, static fn ($event): bool => $event instanceof XmlElementStart));

        self::assertSame(['root', 'real'], array_map(static fn (XmlElementStart $event): string => $event->qualifiedName, $starts));
        self::assertSame([], $document->diagnostics);
    }

    /** @return iterable<string, array{string}> */
    public static function longOpaqueConstructProvider(): iterable
    {
        $padding = str_repeat('x', 70_000);

        yield 'comment' => ['<root><!--'.$padding.'<fake/>--><real/></root>'];
        yield 'CDATA' => ['<root><![CDATA['.$padding.'<fake/>]]><real/></root>'];
        yield 'processing instruction' => ['<root><?work '.$padding.'<fake/>?><real/></root>'];
        yield 'DOCTYPE internal subset' => ['<!DOCTYPE root ['.$padding.'<!ENTITY fake "<fake/>">]><root><real/></root>'];
    }

    public function testKeepsAnUnclosedOpaqueConstructOpaqueToEndOfFile(): void
    {
        $source = '<root><!--'.str_repeat('x', 70_000).'<fake/></root>';
        $document = (new TolerantXmlParser())->parse($source);
        $starts = array_values(array_filter($document->events, static fn ($event): bool => $event instanceof XmlElementStart));

        self::assertSame(['root'], array_map(static fn (XmlElementStart $event): string => $event->qualifiedName, $starts));
        self::assertSame(['XML comment is not closed.'], array_map(static fn ($diagnostic): string => $diagnostic->message, $document->diagnostics));
        self::assertSame(\strlen($source), $document->events[1]->endOffset);
    }

    public function testReportsOnlyTheInnermostUnclosedElementAtEndOfFile(): void
    {
        $document = (new TolerantXmlParser())->parse('<root><child>');

        self::assertSame(['Element "child" is not closed.'], array_map(static fn ($diagnostic): string => $diagnostic->message, $document->diagnostics));
    }

    public function testCapsMaterializedEventsAndDiagnostics(): void
    {
        $events = (new TolerantXmlParser())->parse(str_repeat('<', 21_000));
        $diagnostics = (new TolerantXmlParser())->parse(str_repeat('</missing>', 200));

        self::assertCount(20_000, $events->events);
        self::assertSame(['XML analysis stopped after reaching its structural limit.'], array_map(static fn ($diagnostic): string => $diagnostic->message, $events->diagnostics));
        self::assertCount(101, $diagnostics->diagnostics);
        self::assertSame('XML analysis stopped after reaching its structural limit.', $diagnostics->diagnostics[100]->message);
    }

    public function testDoesNotExposeInvalidUtf8Names(): void
    {
        $document = (new TolerantXmlParser())->parse("<élément2/><\xFFbad/><real/>");
        $starts = array_values(array_filter($document->events, static fn ($event): bool => $event instanceof XmlElementStart));

        self::assertSame(['élément2', 'real'], array_map(static fn (XmlElementStart $event): string => $event->qualifiedName, $starts));
        self::assertSame([], $document->diagnostics);
    }
}
