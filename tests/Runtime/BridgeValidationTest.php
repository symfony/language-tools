<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\Bridge\BridgeFixtureWorkspace;
use Symfony\Lsp\Tests\Support\Bridge\BridgeProcessFixture;
use Symfony\Lsp\Tests\Support\Bridge\ValidationFixtureBuilder;

final class BridgeValidationTest extends TestCase
{
    private BridgeFixtureWorkspace $workspace;
    private BridgeProcessFixture $bridge;
    private ValidationFixtureBuilder $fixtures;

    protected function setUp(): void
    {
        $this->workspace = new BridgeFixtureWorkspace();
        $this->bridge = new BridgeProcessFixture($this->workspace->path);
        $this->fixtures = new ValidationFixtureBuilder($this->workspace);
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testForcesKernelValidationWithoutRequestedSections(): void
    {
        $this->fixtures->writeApplication('');

        $result = $this->runBridge();

        self::assertSame(['status' => 'valid'], $result['configurationValidation'] ?? null);
        self::assertSame([], $result['sections'] ?? null);
        self::assertSame([], $result['errors'] ?? null);
    }

    #[DataProvider('configurationExceptionProvider')]
    public function testReportsInvalidSymfonyConfigurationWithoutRawDetails(string $exception): void
    {
        $this->fixtures->writeApplication(\sprintf(<<<'PHP'
            throw new \RuntimeException(
                'CANARY_UNKNOWN_WRAPPER',
                0,
                new \%s('CANARY_SECRET_CONFIGURATION_VALUE'),
            );
            PHP, $exception));

        $result = $this->runBridge();

        self::assertSame([
            'status' => 'invalid',
            'kind' => 'configuration',
            'path' => 'framework.cache',
        ], $result['configurationValidation'] ?? null);
    }

    /** @return iterable<string, array{string}> */
    public static function configurationExceptionProvider(): iterable
    {
        yield 'invalid configuration' => ['Symfony\\Component\\Config\\Definition\\Exception\\InvalidConfigurationException'];
        yield 'invalid type' => ['Symfony\\Component\\Config\\Definition\\Exception\\InvalidTypeException'];
        yield 'duplicate key' => ['Symfony\\Component\\Config\\Definition\\Exception\\DuplicateKeyException'];
        yield 'forbidden overwrite' => ['Symfony\\Component\\Config\\Definition\\Exception\\ForbiddenOverwriteException'];
    }

    public function testReportsYamlSyntaxLocationWithoutRawDetails(): void
    {
        $this->fixtures->writeApplication(<<<'PHP'
            throw new \RuntimeException(
                'CANARY_UNKNOWN_WRAPPER',
                0,
                new \Symfony\Component\Yaml\Exception\ParseException('CANARY_SECRET_YAML_SNIPPET'),
            );
            PHP);

        $result = $this->runBridge();

        self::assertSame([
            'status' => 'invalid',
            'kind' => 'yaml',
            'file' => 'config/framework.yaml',
            'line' => 7,
        ], $result['configurationValidation'] ?? null);
    }

    public function testKeepsYamlLineWhenTheParserDoesNotExposeAFile(): void
    {
        $this->fixtures->writeApplication(<<<'PHP'
            throw new \Symfony\Component\Yaml\Exception\ParseException('CANARY_SECRET_YAML_SNIPPET', false);
            PHP);

        $result = $this->runBridge();

        self::assertSame([
            'status' => 'invalid',
            'kind' => 'yaml',
            'line' => 7,
        ], $result['configurationValidation'] ?? null);
    }

    public function testReportsXmlSyntaxWithoutParsingRawDetails(): void
    {
        $this->fixtures->writeApplication(<<<'PHP'
            throw new \RuntimeException(
                'CANARY_UNKNOWN_WRAPPER',
                0,
                new \Symfony\Component\Config\Util\Exception\XmlParsingException('CANARY_SECRET_XML_SNIPPET'),
            );
            PHP);

        $result = $this->runBridge();

        self::assertSame([
            'status' => 'invalid',
            'kind' => 'xml',
        ], $result['configurationValidation'] ?? null);
    }

    public function testDoesNotClassifyProjectPhpParseErrorsAsConfigurationFailures(): void
    {
        $this->fixtures->writeApplication("require dirname(__DIR__).'/config/broken.php';");
        $this->workspace->write('config/broken.php', "<?php\n\nfunction broken( {\n    CANARY_SECRET_PHP_VALUE\n");

        $result = $this->runBridge();

        self::assertSame(['status' => 'unavailable'], $result['configurationValidation'] ?? null);
    }

    public function testReportsUnrelatedApplicationExceptionsAsUnavailable(): void
    {
        $this->fixtures->writeApplication("throw new \\RuntimeException('CANARY_UNKNOWN_APPLICATION_EXCEPTION');");

        $result = $this->runBridge();

        self::assertSame(['status' => 'unavailable'], $result['configurationValidation'] ?? null);
    }

    public function testDoesNotClassifyApplicationDefinedConfigurationExceptions(): void
    {
        $this->fixtures->writeApplication("throw new ApplicationConfigurationException('CANARY_APPLICATION_CONFIGURATION_EXCEPTION');");

        $result = $this->runBridge();

        self::assertSame(['status' => 'unavailable'], $result['configurationValidation'] ?? null);
    }

    /** @return array<array-key, mixed> */
    private function runBridge(): array
    {
        $process = $this->bridge->run();

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        self::assertStringNotContainsString('CANARY_', $process->stdout);
        self::assertIsArray($process->snapshot);

        return $process->snapshot;
    }
}
