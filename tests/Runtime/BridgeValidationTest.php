<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;

final class BridgeValidationTest extends AbstractBridgeTestCase
{
    public function testForcesKernelValidationWithoutRequestedSections(): void
    {
        $this->writeValidationApplication('');

        $result = $this->runBridge();

        self::assertSame(['status' => 'valid'], $result['configurationValidation'] ?? null);
        self::assertSame([], $result['sections'] ?? null);
        self::assertSame([], $result['errors'] ?? null);
    }

    #[DataProvider('configurationExceptionProvider')]
    public function testReportsInvalidSymfonyConfigurationWithoutRawDetails(string $exception): void
    {
        $this->writeValidationApplication(\sprintf(<<<'PHP'
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
        $this->writeValidationApplication(<<<'PHP'
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
        $this->writeValidationApplication(<<<'PHP'
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
        $this->writeValidationApplication(<<<'PHP'
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
        $this->writeValidationApplication("require dirname(__DIR__).'/config/broken.php';");
        file_put_contents($this->temporaryDirectory.'/config/broken.php', "<?php\n\nfunction broken( {\n    CANARY_SECRET_PHP_VALUE\n");

        $result = $this->runBridge();

        self::assertSame(['status' => 'unavailable'], $result['configurationValidation'] ?? null);
    }

    public function testReportsUnrelatedApplicationExceptionsAsUnavailable(): void
    {
        $this->writeValidationApplication("throw new \\RuntimeException('CANARY_UNKNOWN_APPLICATION_EXCEPTION');");

        $result = $this->runBridge();

        self::assertSame(['status' => 'unavailable'], $result['configurationValidation'] ?? null);
    }

    public function testDoesNotClassifyApplicationDefinedConfigurationExceptions(): void
    {
        $this->writeValidationApplication("throw new ApplicationConfigurationException('CANARY_APPLICATION_CONFIGURATION_EXCEPTION');");

        $result = $this->runBridge();

        self::assertSame(['status' => 'unavailable'], $result['configurationValidation'] ?? null);
    }

    /** @return array<array-key, mixed> */
    private function runBridge(): array
    {
        exec(\sprintf(
            '%s %s --project=%s 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        self::assertStringNotContainsString('CANARY_', $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
    }

    private function writeValidationApplication(string $boot): void
    {
        mkdir($this->temporaryDirectory.'/config');
        file_put_contents($this->temporaryDirectory.'/config/framework.yaml', "framework:\n    secret: CANARY_SECRET_YAML_VALUE\n");
        file_put_contents($this->temporaryDirectory.'/vendor/autoload.php', str_replace('__BOOT__', $boot, <<<'PHP'
            <?php
            namespace Composer;
            final class InstalledVersions
            {
                public static function getPrettyVersion(string $package): ?string { return '8.0.6'; }
            }
            namespace Symfony\Component\Config\Definition\Exception;
            class InvalidConfigurationException extends \RuntimeException
            {
                public function getPath(): ?string { return '.framework..cache.'; }
            }
            class InvalidTypeException extends InvalidConfigurationException {}
            class DuplicateKeyException extends InvalidConfigurationException {}
            class ForbiddenOverwriteException extends InvalidConfigurationException {}
            namespace Symfony\Component\Yaml\Exception;
            class ParseException extends \RuntimeException
            {
                public function __construct(string $message, private bool $withFile = true) { parent::__construct($message); }
                public function getParsedFile(): string { return $this->withFile ? \dirname(__DIR__).'/config/framework.yaml' : null; }
                public function getParsedLine(): int { return 7; }
                public function getSnippet(): string { return 'CANARY_SECRET_YAML_SNIPPET'; }
            }
            namespace Symfony\Component\Config\Util\Exception;
            class XmlParsingException extends \InvalidArgumentException {}
            namespace App;
            class ApplicationConfigurationException extends \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException {}
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function boot(): void
                {
                    __BOOT__
                }
                public function shutdown(): void {}
            }
            PHP));
    }
}
