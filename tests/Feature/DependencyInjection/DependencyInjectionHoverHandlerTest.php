<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionHoverHandler;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSymbolResolver;
use Symfony\Lsp\Feature\DependencyInjection\Parameter;
use Symfony\Lsp\Feature\DependencyInjection\ParameterIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpAutowireReferenceExtractor;
use Symfony\Lsp\Feature\DependencyInjection\Service;
use Symfony\Lsp\Feature\DependencyInjection\ServiceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionExtractor;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class DependencyInjectionHoverHandlerTest extends TestCase
{
    public function testDisplaysSafeServiceAndParameterMetadata(): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $text = <<<'YAML'
            parameters:
                app.api_key: 'CANARY_SECRET_VALUE'
            services:
                app.consumer:
                    arguments: ['@app.mailer', '%app.api_key%']
            YAML;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $converter = new PositionConverter();
        $yamlExtractor = new YamlDependencyInjectionExtractor($converter);
        $autowireExtractor = new PhpAutowireReferenceExtractor($converter);
        $sourceIndexes = new DependencyInjectionSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace($yamlExtractor->extract($uri, $text));
        $serviceIndexes = new ServiceIndexRegistry();
        $serviceIndexes->forProject($project)->replace(true, new Service(
            'app.mailer',
            'App\\Mailer',
            null,
            false,
            true,
            'Use app.new_mailer.',
            ['kernel.reset'],
            'mailer',
            ['App\\MailerInterface'],
            ['app.mailer', 'mailer.inner'],
        ));
        $parameterIndexes = new ParameterIndexRegistry();
        $parameterIndexes->forProject($project)->replace(
            true,
            new Parameter('app.api_key', 'Use app.new_api_key.'),
        );
        $handler = new DependencyInjectionHoverHandler(
            new DocumentContextResolver($documents, $projects),
            new DependencyInjectionSymbolResolver($converter, $yamlExtractor, $autowireExtractor),
            $serviceIndexes,
            $parameterIndexes,
            $sourceIndexes,
        );

        $serviceHover = $handler->hover($this->params($uri, $text, 'app.mailer', $converter));
        $parameterHover = $handler->hover($this->params($uri, $text, 'app.api_key%', $converter));
        self::assertIsArray($serviceHover);
        self::assertIsArray($serviceHover['contents']);
        self::assertIsArray($parameterHover);
        self::assertIsArray($parameterHover['contents']);

        self::assertSame(<<<'MARKDOWN'
            Service: `app.mailer`

            Class: `App\Mailer`

            Visibility: private

            Lazy: yes

            Deprecated: Use app.new_mailer.

            Decorates: `mailer`

            Tags: `kernel.reset`

            Autowiring types: `App\MailerInterface`

            Decoration stack: `app.mailer` → `mailer.inner`
            MARKDOWN, $serviceHover['contents']['value'] ?? null);
        self::assertSame(<<<'MARKDOWN'
            Parameter: `app.api_key`

            Deprecated: Use app.new_api_key.
            MARKDOWN, $parameterHover['contents']['value'] ?? null);
        self::assertStringNotContainsString(
            'CANARY_SECRET_VALUE',
            json_encode([$serviceHover, $parameterHover], \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function params(string $uri, string $text, string $needle, PositionConverter $converter): array
    {
        $offset = strpos($text, $needle);
        self::assertIsInt($offset);
        $position = $converter->toPosition($text, $offset + 1);

        return [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ];
    }
}
