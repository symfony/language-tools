<?php

namespace Symfony\Lsp\Tests\Check;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Check\CheckArgumentsTokenizer;
use Symfony\Lsp\Check\CheckOptions;
use Symfony\Lsp\Check\CheckOptionsParser;
use Symfony\Lsp\Feature\DiagnosticCodeRegistry;
use Symfony\Lsp\Project\AnalysisSettings;
use Symfony\Lsp\Project\InvalidConfigurationException;

final class CheckOptionsParserTest extends TestCase
{
    private CheckOptionsParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CheckOptionsParser(
            new DiagnosticCodeRegistry(),
            new AnalysisSettings(),
            new CheckArgumentsTokenizer(),
        );
    }

    public function testParsesAnalysisBaselineAndFailureOptions(): void
    {
        $options = $this->options([
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

    public function testPreservesAliasesRepeatedFormatsAndTheOptionSeparator(): void
    {
        $options = $this->options([
            '-h',
            '--format=json',
            '--format=json',
            '--',
            '--format=sarif',
            '--debug',
        ]);

        self::assertSame('json', $options->format);
        self::assertTrue($options->help);
        self::assertSame(['--format=sarif', '--debug'], $options->selectors);
        self::assertSame([], $options->overrides);
    }

    public function testAppliesBooleanAndValueOptionsInArgumentOrder(): void
    {
        $options = $this->options([
            '--debug',
            '--no-debug',
            '--container-project-root=/first',
            '--no-container-project-root',
            '--container-project-root=/last',
            '--translation-diagnostics',
            '--no-translation-diagnostics',
            '--runtime-indexing',
            '--source-only',
        ]);

        self::assertSame([
            'debug' => false,
            'containerProjectRoot' => '/last',
            'translationDiagnostics' => false,
            'runtimeIndexing' => false,
        ], $options->overrides);
    }

    public function testAcceptsAnExplicitEmptyBlockingCodeList(): void
    {
        self::assertSame([], $this->options(['--fail-on='])->blockingCodes);
    }

    public function testAcceptsGitLabFormat(): void
    {
        self::assertSame('gitlab', $this->options(['--format=gitlab'])->format);
    }

    /** @param list<string> $arguments */
    #[DataProvider('invalidArguments')]
    public function testReportsExactErrorsWithTheDetectedFormat(array $arguments, string $format, string $message): void
    {
        $result = $this->parser->parse($arguments);

        self::assertSame($format, $result->format);
        self::assertInstanceOf(InvalidConfigurationException::class, $result->value);
        self::assertSame($message, $result->value->getMessage());
    }

    /** @return iterable<string, array{list<string>, string, string}> */
    public static function invalidArguments(): iterable
    {
        yield 'unknown option before JSON format' => [
            ['--unknown=value', '--format=json'],
            'json',
            'Unknown check option "--unknown".',
        ];
        yield 'unknown option before SARIF format' => [
            ['--unknown=value', '--format=sarif'],
            'sarif',
            'Unknown check option "--unknown".',
        ];
        yield 'invalid format' => [
            ['--format=xml'],
            'xml',
            'The --format option must be human, json, github, gitlab or sarif.',
        ];
        yield 'empty format' => [
            ['--format='],
            '',
            'The --format option must be human, json, github, gitlab or sarif.',
        ];
        yield 'conflicting formats' => [
            ['--format=json', '--format=github'],
            'human',
            'The --format option cannot select more than one format.',
        ];
        yield 'empty value' => [
            ['--workspace='],
            'human',
            'The check option "--workspace=" requires a value.',
        ];
        yield 'missing value' => [
            ['--workspace'],
            'human',
            'The check option "--workspace" requires a value.',
        ];
        yield 'unknown diagnostic code' => [
            ['--fail-on=route.removed'],
            'human',
            'Unknown diagnostic code "route.removed". Run "symfony-lsp check --list-codes".',
        ];
        yield 'invalid PHP command' => [
            ['--php-command=invalid'],
            'human',
            'The --php-command option must be a JSON list of command arguments.',
        ];
        yield 'invalid timeout' => [
            ['--timeout=0'],
            'human',
            'The --timeout option must be a positive number.',
        ];
        yield 'conflicting baseline modes' => [
            ['--generate-baseline', '--refresh-baseline'],
            'human',
            'The --generate-baseline and --refresh-baseline options cannot be combined.',
        ];
        yield 'strict mode without baseline' => [
            ['--strict-baseline'],
            'human',
            'The --strict-baseline option requires --baseline.',
        ];
    }

    /** @param list<string> $arguments */
    private function options(array $arguments): CheckOptions
    {
        $result = $this->parser->parse($arguments);
        if ($result->value instanceof InvalidConfigurationException) {
            throw $result->value;
        }

        return $result->value;
    }
}
