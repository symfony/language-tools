<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionRenameHandler;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class DependencyInjectionRenameHandlerTest extends TestCase
{
    public function testRenamesApplicationOwnedDeclarationsAndStaticReferences(): void
    {
        $yamlUri = 'file:///workspace/config/services.yaml';
        $yaml = <<<'YAML'
            services:
                app.mailer: ~
                mailer: '@app.mailer'
            YAML;
        $phpUri = 'file:///workspace/src/Consumer.php';
        $php = "<?php use Symfony\\Component\\DependencyInjection\\Attribute\\Autowire; #[Autowire(service: 'app.mailer')] final class Consumer {}";
        $kit = (new ProjectTestKit())->open($yamlUri, $yaml)->open($phpUri, $php)->index();
        $handler = $kit->get(DependencyInjectionRenameHandler::class);
        $params = $kit->offset($yamlUri, strpos($yaml, 'app.mailer') + 1);

        self::assertSame('app.mailer', $handler->prepare($kit->positioned($params))['placeholder'] ?? null);
        $result = $handler->rename($kit->rename($params, 'app.primary_mailer'));
        self::assertIsArray($result);
        self::assertIsArray($result['documentChanges']);

        $uris = [];
        $newTexts = [];
        $editCount = 0;
        foreach ($result['documentChanges'] as $change) {
            self::assertIsArray($change);
            self::assertIsArray($change['textDocument']);
            self::assertIsString($change['textDocument']['uri']);
            self::assertIsArray($change['edits']);
            $uris[] = $change['textDocument']['uri'];
            $editCount += \count($change['edits']);
            foreach ($change['edits'] as $edit) {
                self::assertIsArray($edit);
                self::assertIsString($edit['newText']);
                $newTexts[] = $edit['newText'];
            }
        }

        self::assertSame([$yamlUri, $phpUri], $uris);
        self::assertSame(['app.primary_mailer'], array_values(array_unique($newTexts)));
        self::assertSame(3, $editCount);
    }

    public function testRenamesApplicationOwnedParametersWithoutChangingDelimiters(): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $text = <<<'YAML'
            parameters:
                app.storage_dir: /storage
            services:
                app.consumer:
                    arguments: ['%app.storage_dir%']
            YAML;
        $kit = (new ProjectTestKit())->open($uri, $text)->index();

        $result = $kit->get(DependencyInjectionRenameHandler::class)->rename($kit->rename($kit->offset($uri, strpos($text, 'app.storage_dir') + 1), 'app.data_dir'));
        self::assertIsArray($result);
        self::assertIsArray($result['documentChanges']);
        self::assertIsArray($result['documentChanges'][0]);
        self::assertIsArray($result['documentChanges'][0]['edits']);

        self::assertSame(
            ['app.data_dir', 'app.data_dir'],
            array_column($result['documentChanges'][0]['edits'], 'newText'),
        );
    }

    public function testRejectsNamesCollidingWithRuntimeOrSourceSymbols(): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $text = <<<'YAML'
            parameters:
                current.parameter: value
                source.parameter: value
            services:
                current.service: ~
                source.service: ~
            YAML;
        $kit = (new ProjectTestKit())->open($uri, $text)->index()->runtime('container', [
            'servicesComplete' => true,
            'items' => [['id' => 'runtime.service', 'public' => false, 'lazy' => false]],
            'parametersComplete' => true,
            'parameters' => [['name' => 'runtime.parameter']],
        ]);
        $handler = $kit->get(DependencyInjectionRenameHandler::class);
        $serviceParams = $kit->offset($uri, strpos($text, 'current.service') + 1);
        $parameterParams = $kit->offset($uri, strpos($text, 'current.parameter') + 1);

        self::assertNull($handler->rename($kit->rename($serviceParams, 'runtime.service')));
        self::assertNull($handler->rename($kit->rename($serviceParams, 'source.service')));
        self::assertNull($handler->rename($kit->rename($parameterParams, 'runtime.parameter')));
        self::assertNull($handler->rename($kit->rename($parameterParams, 'source.parameter')));
    }
}
