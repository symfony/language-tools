<?php

namespace Symfony\Lsp\Tests\Feature\Configuration;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Configuration\ConfigurationNode;
use Symfony\Lsp\Feature\Configuration\ConfigurationValueValidator;
use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Project\Project;

final class ConfigurationValueValidatorTest extends TestCase
{
    public function testValidatesLiteralAndDynamicValues(): void
    {
        $validator = new ConfigurationValueValidator(new EnvironmentIndexRegistry());

        self::assertTrue($validator->acceptsValue($this->node('boolean'), 'true'));
        self::assertFalse($validator->acceptsValue($this->node('boolean'), 'maybe'));
        self::assertTrue($validator->acceptsValue($this->node('enum', ['dev', 'prod']), 'prod'));
        self::assertFalse($validator->acceptsValue($this->node('enum', ['dev', 'prod']), 'old'));
        self::assertTrue($validator->acceptsValue($this->node('integer'), '%app.port%'));
        self::assertTrue($validator->acceptsValue($this->node('integer'), '$port'));
    }

    public function testHonorsProbedArrayNormalization(): void
    {
        $validator = new ConfigurationValueValidator(new EnvironmentIndexRegistry());
        $strict = $this->node('array');
        $enableable = $this->node('array', accepts: ['null' => true, 'true' => true, 'false' => true]);
        $shorthand = $this->node('array', accepts: ['scalar' => true]);

        self::assertFalse($validator->acceptsValue($strict, '~'));
        self::assertFalse($validator->acceptsValue($strict, 'true'));
        self::assertFalse($validator->acceptsValue($strict, 'async'));
        self::assertTrue($validator->acceptsValue($strict, '{ enabled: true }'));
        self::assertTrue($validator->acceptsValue($enableable, '~'));
        self::assertTrue($validator->acceptsValue($enableable, 'true'));
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
        $validator = new ConfigurationValueValidator($environmentIndexes);

        self::assertSame('string', $validator->environmentType($project, '%env(APP_NAME)%'));
        self::assertSame('array', $validator->environmentType($project, "'%env(json:APP_CONFIG)%'"));
        self::assertNull($validator->environmentType($project, '%env(unknown:APP_VALUE)%'));
        self::assertTrue($validator->acceptsType($this->node('float'), 'int|float'));
        self::assertFalse($validator->acceptsType($this->node('boolean'), 'array'));
    }

    /**
     * @param list<string|int|float|bool|null> $allowedValues
     * @param array<string, bool>              $accepts
     */
    private function node(string $type, array $allowedValues = [], array $accepts = []): ConfigurationNode
    {
        return new ConfigurationNode('option', $type, false, false, null, null, null, false, $allowedValues, [], null, $accepts);
    }
}
