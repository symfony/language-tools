<?php

namespace Symfony\Lsp\Tests\Check;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Check\CheckOptionsParser;
use Symfony\Lsp\Check\DiagnosticCodeRegistry;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\InvalidConfigurationException;

final class CheckOptionsParserTest extends TestCase
{
    private CheckOptionsParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CheckOptionsParser(new DiagnosticCodeRegistry(), new AnalysisSettings());
    }

    public function testParsesAnalysisBaselineAndFailureOptions(): void
    {
        $options = $this->parser->parse([
            '--format=json',
            '--workspace=application',
            '--source-only',
            '--no-container-project-root',
            '--environment=test',
            '--php-command=["symfony","php"]',
            '--fail-on=route.not_found,config.deprecated_key',
            '--baseline=diagnostics.json',
            '--strict-baseline',
            '--verbose',
            'src',
        ]);

        self::assertSame('json', $options->format);
        self::assertSame('application', $options->workspace);
        self::assertSame(['src'], $options->selectors);
        self::assertSame([
            'runtimeIndexing' => false,
            'containerProjectRoot' => null,
            'environment' => 'test',
            'phpCommand' => ['symfony', 'php'],
        ], $options->overrides);
        self::assertSame(['config.deprecated_key', 'route.not_found'], $options->blockingCodes);
        self::assertSame('diagnostics.json', $options->baselinePath);
        self::assertTrue($options->strictBaseline);
        self::assertTrue($options->verbose);
    }

    public function testAcceptsAnExplicitEmptyBlockingCodeList(): void
    {
        self::assertSame([], $this->parser->parse(['--fail-on='])->blockingCodes);
    }

    public function testRejectsUnknownDiagnosticCodes(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unknown diagnostic code');

        $this->parser->parse(['--fail-on=route.removed']);
    }

    public function testRecognizesMachineFormatsBeforeAnotherInvocationError(): void
    {
        self::assertSame('json', $this->parser->selectedFormat(['--unknown=value', '--format=json']));
        self::assertSame('sarif', $this->parser->selectedFormat(['--unknown=value', '--format=sarif']));
        self::assertSame('human', $this->parser->selectedFormat(['--', '--format=json']));
    }

    public function testRejectsConflictingOutputFormats(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('more than one format');

        $this->parser->selectedFormat(['--format=json', '--format=github']);
    }
}
