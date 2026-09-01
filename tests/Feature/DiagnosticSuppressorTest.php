<?php

namespace Symfony\Lsp\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\CollectedDiagnostic;
use Symfony\Lsp\Feature\DiagnosticCodeRegistry;
use Symfony\Lsp\Feature\DiagnosticSuppressor;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlCommentParser;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class DiagnosticSuppressorTest extends TestCase
{
    #[DataProvider('nativeCommentProvider')]
    public function testSuppressesDiagnosticsWithNativeComments(string $languageId, string $source, int $line): void
    {
        $diagnostics = $this->suppressor()->suppress(
            new Document('file:///workspace/source', $languageId, 1, $source),
            [$this->diagnostic('template.not_found', $line)],
        );

        self::assertSame([], $diagnostics);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function nativeCommentProvider(): iterable
    {
        yield 'PHP next line' => [
            'php',
            "<?php\n// @symfony-lsp-ignore template.not_found (the missing template is intentional)\nrender('missing');\n",
            2,
        ];
        yield 'PHP same line' => [
            'php',
            "<?php\nrender('missing'); // @symfony-lsp-ignore template.not_found\n",
            1,
        ];
        yield 'Twig' => [
            'twig',
            "{# @symfony-lsp-ignore template.not_found #}\n{{ include('missing.html.twig') }}\n",
            1,
        ];
        yield 'YAML' => [
            'yaml',
            "# @symfony-lsp-ignore template.not_found\ntemplate: missing.html.twig\n",
            1,
        ];
        yield 'XML' => [
            'xml',
            "<!-- @symfony-lsp-ignore template.not_found -->\n<template>missing.html.twig</template>\n",
            1,
        ];
    }

    #[DataProvider('nonCommentProvider')]
    public function testDoesNotRecognizeDirectivesOutsideComments(string $languageId, string $source, int $line): void
    {
        $diagnostics = $this->suppressor()->suppress(
            new Document('file:///workspace/source', $languageId, 1, $source),
            [$diagnostic = $this->diagnostic('template.not_found', $line)],
        );

        self::assertSame([$diagnostic], $diagnostics);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function nonCommentProvider(): iterable
    {
        yield 'PHP string' => [
            'php',
            "<?php\n\$marker = '// @symfony-lsp-ignore template.not_found';\nrender('missing');\n",
            2,
        ];
        yield 'Twig string' => [
            'twig',
            "{{ '@symfony-lsp-ignore template.not_found' }}\n{{ include('missing.html.twig') }}\n",
            1,
        ];
        yield 'Twig verbatim content' => [
            'twig',
            "{% verbatim %}\n{# @symfony-lsp-ignore template.not_found #}\n{% endverbatim %}\n",
            2,
        ];
        yield 'YAML block scalar' => [
            'yaml',
            "content: |\n    # @symfony-lsp-ignore template.not_found\ntemplate: missing.html.twig\n",
            2,
        ];
        yield 'XML CDATA' => [
            'xml',
            "<![CDATA[\n<!-- @symfony-lsp-ignore template.not_found -->\n]]>\n",
            2,
        ];
    }

    public function testDoesNotSkipBlankLinesAfterStandaloneComments(): void
    {
        $source = "<?php\n// @symfony-lsp-ignore template.not_found\n\nrender('missing');\n";
        $diagnostic = $this->diagnostic('template.not_found', 3);

        self::assertSame(
            [$diagnostic],
            $this->suppressor()->suppress(new Document('file:///workspace/source.php', 'php', 1, $source), [$diagnostic]),
        );
    }

    public function testSuppressesYamlDiagnosticsAfterIncompleteSyntax(): void
    {
        $source = "broken: !<unterminated\n# @symfony-lsp-ignore template.not_found\ntemplate: missing.html.twig\n";

        self::assertSame(
            [],
            $this->suppressor()->suppress(
                new Document('file:///workspace/source.yaml', 'yaml', 1, $source),
                [$this->diagnostic('template.not_found', 2)],
            ),
        );
    }

    public function testSuppressesOneOccurrencePerListedCode(): void
    {
        $source = "<?php\n// @symfony-lsp-ignore template.not_found\nfirst(); second();\n";
        $diagnostics = [
            $this->diagnostic('template.not_found', 2, 0),
            $second = $this->diagnostic('template.not_found', 2, 9),
        ];

        self::assertSame([$second], $this->suppressor()->suppress(new Document('file:///workspace/source.php', 'php', 1, $source), $diagnostics));

        $source = "<?php\n// @symfony-lsp-ignore template.not_found, template.not_found\nfirst(); second();\n";

        self::assertSame([], $this->suppressor()->suppress(new Document('file:///workspace/source.php', 'php', 1, $source), $diagnostics));
    }

    public function testReportsMalformedAndUnknownSuppressions(): void
    {
        $source = <<<'PHP'
            <?php
            // @symfony-lsp-ignore missing.code
            // @symfony-lsp-ignore
            render('missing');
            PHP;

        $diagnostics = $this->suppressor()->suppress(
            new Document('file:///workspace/source.php', 'php', 1, $source),
            [$this->diagnostic('template.not_found', 3)],
        );

        self::assertSame(
            ['template.not_found', 'suppression.invalid', 'suppression.invalid'],
            array_column($diagnostics, 'code'),
        );
        self::assertSame(
            ['Unknown diagnostic code "missing.code" in suppression.', 'Invalid diagnostic suppression; expected "@symfony-lsp-ignore code".'],
            array_column(\array_slice($diagnostics, 1), 'message'),
        );
        self::assertSame([2, 2], array_column(\array_slice($diagnostics, 1), 'severity'));
    }

    public function testRejectsDirectivesContainingUnknownCodes(): void
    {
        $source = "<?php\n// @symfony-lsp-ignore template.not_found, missing.code\nrender('missing');\n";

        $diagnostics = $this->suppressor()->suppress(
            new Document('file:///workspace/source.php', 'php', 1, $source),
            [$this->diagnostic('template.not_found', 2)],
        );

        self::assertSame(['template.not_found', 'suppression.invalid'], array_column($diagnostics, 'code'));
    }

    public function testSuppressesDetailedDiagnostics(): void
    {
        $source = "<?php\n// @symfony-lsp-ignore template.not_found\nrender('missing');\n";
        $diagnostics = $this->suppressor()->suppressCollected(
            new Document('file:///workspace/source.php', 'php', 1, $source),
            [new CollectedDiagnostic('template', $this->diagnostic('template.not_found', 2))],
        );

        self::assertSame([], $diagnostics);
    }

    private function suppressor(): DiagnosticSuppressor
    {
        $treeSitter = new NativeTreeSitterParser(new TreeSitterResultDecoder());

        return new DiagnosticSuppressor(
            new PositionConverter(),
            new LspProtocolMapper(),
            new DiagnosticCodeRegistry(),
            new PhpCommentParser(),
            new TwigCommentParser(),
            new YamlCommentParser($treeSitter),
            new XmlCommentParser(),
        );
    }

    /** @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, severity: int, source: string, code: string, message: string} */
    private function diagnostic(string $code, int $line, int $character = 0): array
    {
        return [
            'range' => [
                'start' => ['line' => $line, 'character' => $character],
                'end' => ['line' => $line, 'character' => $character + 1],
            ],
            'severity' => 1,
            'source' => 'symfony',
            'code' => $code,
            'message' => 'Stub diagnostic.',
        ];
    }
}
