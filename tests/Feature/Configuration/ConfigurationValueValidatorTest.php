<?php

namespace Symfony\Lsp\Tests\Feature\Configuration;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Configuration\ConfigurationNode;
use Symfony\Lsp\Feature\Configuration\ConfigurationValueValidator;
use Symfony\Lsp\Feature\Environment\EnvironmentExpressionParser;
use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Project\Project;

final class ConfigurationValueValidatorTest extends TestCase
{
    public function testValidatesLiteralAndDynamicValues(): void
    {
        $validator = new ConfigurationValueValidator(new EnvironmentIndexRegistry(), new EnvironmentExpressionParser());

        self::assertTrue($validator->acceptsValue($this->node('boolean'), 'true'));
        self::assertFalse($validator->acceptsValue($this->node('boolean'), 'maybe'));
        self::assertTrue($validator->acceptsValue($this->node('enum', ['dev', 'prod']), 'prod'));
        self::assertFalse($validator->acceptsValue($this->node('enum', ['dev', 'prod']), 'old'));
        self::assertTrue($validator->acceptsValue($this->node('enum', [true, false, 'auto']), 'true'));
        self::assertTrue($validator->acceptsValue($this->node('enum', [true, false, 'auto']), 'false'));
        self::assertTrue($validator->acceptsValue($this->node('enum', [true, false, 'auto']), 'auto'));
        self::assertFalse($validator->acceptsValue($this->node('enum', [true, false, 'auto']), "'true'"));
        self::assertTrue($validator->acceptsValue($this->node('enum', ['yes', 'no']), 'yes'));
        self::assertFalse($validator->acceptsValue($this->node('boolean'), "'true'"));
        self::assertFalse($validator->acceptsValue($this->node('boolean'), 'yes'));
        self::assertFalse($validator->acceptsValue($this->node('integer'), "'12'"));
        self::assertTrue($validator->acceptsValue($this->node('integer'), '%app.port%'));
        self::assertTrue($validator->acceptsValue($this->node('integer'), '$port'));
    }

    public function testValidatesEnumCasesConservatively(): void
    {
        $validator = new ConfigurationValueValidator(new EnvironmentIndexRegistry(), new EnvironmentExpressionParser());
        $backed = $this->node('enum', ['schema', 'migrate'], ['App\\ResetMode::SCHEMA', 'App\\ResetMode::MIGRATE']);
        $pure = $this->node('enum', [], ['App\\ResetMode::SCHEMA', 'App\\ResetMode::MIGRATE']);
        $truncated = $this->node('enum', ['schema'], ['App\\ResetMode::SCHEMA'], allowedValuesTruncated: true);

        self::assertTrue($validator->acceptsValue($backed, 'schema'));
        self::assertTrue($validator->acceptsValue($backed, '!php/enum App\\ResetMode::SCHEMA'));
        self::assertFalse($validator->acceptsValue($backed, '!php/enum App\\ResetMode::UNKNOWN'));
        self::assertTrue($validator->acceptsValue($pure, '!php/enum App\\ResetMode::SCHEMA'));
        self::assertFalse($validator->acceptsValue($pure, 'schema'));
        self::assertTrue($validator->acceptsValue($this->node('variable'), '!php/enum App\\ResetMode::SCHEMA'));
        self::assertTrue($validator->acceptsValue($truncated, 'migrate'));
        self::assertTrue($validator->acceptsValue($truncated, '!php/enum App\\ResetMode::MIGRATE'));
    }

    public function testHonorsProbedArrayNormalization(): void
    {
        $validator = new ConfigurationValueValidator(new EnvironmentIndexRegistry(), new EnvironmentExpressionParser());
        $strict = $this->node('array');
        $enableable = $this->node('array', accepts: ['null' => true, 'true' => true, 'false' => true]);
        $shorthand = $this->node('array', accepts: ['scalar' => true]);

        self::assertFalse($validator->acceptsValue($strict, '~'));
        self::assertFalse($validator->acceptsValue($strict, 'true'));
        self::assertFalse($validator->acceptsValue($strict, 'async'));
        self::assertTrue($validator->acceptsValue($strict, '{ enabled: true }'));
        self::assertTrue($validator->acceptsValue($enableable, '~'));
        self::assertTrue($validator->acceptsValue($enableable, 'true'));
        self::assertFalse($validator->acceptsValue($enableable, 'yes'));
        self::assertFalse($validator->acceptsValue($enableable, 'async'));
        self::assertTrue($validator->acceptsValue($shorthand, 'async'));
        self::assertTrue($validator->acceptsType($shorthand, 'string'));
        self::assertFalse($validator->acceptsType($strict, 'string'));
    }

    public function testResolvesAndChecksEnvironmentProcessorTypes(): void
    {
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $environmentIndexes = new EnvironmentIndexRegistry();
        $environmentIndexes->forProject($project)->replaceProcessors(['bool' => 'bool', 'json' => 'array', 'number' => 'int|float']);
        $validator = new ConfigurationValueValidator($environmentIndexes, new EnvironmentExpressionParser());

        self::assertSame('string', $validator->environmentType($project, '%env(APP_NAME)%'));
        self::assertSame('array', $validator->environmentType($project, "'%env(json:APP_CONFIG)%'"));
        self::assertNull($validator->environmentType($project, '%env(unknown:APP_VALUE)%'));
        self::assertTrue($validator->acceptsType($this->node('float'), 'int|float'));
        self::assertFalse($validator->acceptsType($this->node('boolean'), 'array'));
    }

    /**
     * @param list<string|int|float|bool|null> $allowedValues
     * @param list<string>                     $allowedEnumCases
     * @param array<string, bool>              $accepts
     */
    private function node(string $type, array $allowedValues = [], array $allowedEnumCases = [], array $accepts = [], bool $allowedValuesTruncated = false): ConfigurationNode
    {
        return new ConfigurationNode('option', $type, false, false, null, null, null, false, $allowedValues, $allowedEnumCases, [], null, $accepts, allowedValuesTruncated: $allowedValuesTruncated);
    }
}
