<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionDefinitionHandler;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionReferencesHandler;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class DependencyInjectionNavigationTest extends TestCase
{
    public function testNavigatesToServiceDeclarationsAndClasses(): void
    {
        [$kit, $params] = $this->kit();

        $locations = $kit->get(DependencyInjectionDefinitionHandler::class)->definition($kit->positioned($params));

        self::assertSame([
            'file:///workspace/config/services.yaml',
            'file:///workspace/config/decorator.yaml',
            'file:///workspace/src/RuntimeMailer.php',
            'file:///workspace/src/Mailer.php',
            'file:///workspace/src/RuntimeAlias.php',
        ], $kit->targets($locations));
    }

    public function testFindsYamlAndAutowireReferencesWithDeclarations(): void
    {
        [$kit, $params] = $this->kit();

        $locations = $kit->get(DependencyInjectionReferencesHandler::class)->references($kit->references($params));

        self::assertSame([
            'file:///workspace/config/services.yaml',
            'file:///workspace/src/Consumer.php',
            'file:///workspace/config/decorator.yaml',
            'file:///workspace/config/services.yaml',
        ], $kit->targets($locations));
    }

    /** @return array{ProjectTestKit, array{textDocument: array{uri: string}, position: array{line: int, character: int}}} */
    private function kit(): array
    {
        $yamlUri = 'file:///workspace/config/services.yaml';
        $yaml = <<<'YAML'
            services:
                app.mailer:
                    class: App\Mailer
                mailer: '@app.mailer'
            YAML;
        $classUri = 'file:///workspace/src/Mailer.php';
        $class = '<?php namespace App; final class Mailer {}';
        $consumerUri = 'file:///workspace/src/Consumer.php';
        $consumer = "<?php use Symfony\\Component\\DependencyInjection\\Attribute\\Autowire; #[Autowire(service: 'app.mailer')] final class Consumer {}";
        $kit = (new ProjectTestKit())
            ->open($yamlUri, $yaml)
            ->open($classUri, $class)
            ->open($consumerUri, $consumer)
            ->open('file:///workspace/config/decorator.yaml', "services:\n    app.decorator:\n        decorates: app.mailer\n")
            ->open('file:///workspace/src/RuntimeMailer.php', '<?php namespace App; final class RuntimeMailer {}')
            ->open('file:///workspace/src/RuntimeAlias.php', '<?php namespace App; final class RuntimeAlias {}')
            ->index()
            ->runtime('container', ['servicesComplete' => true, 'items' => [
                ['id' => 'app.mailer', 'class' => 'App\\RuntimeMailer', 'alias' => 'runtime.alias', 'public' => false, 'lazy' => false],
                ['id' => 'runtime.alias', 'class' => 'App\\RuntimeAlias', 'public' => false, 'lazy' => false],
            ]])
        ;

        return [$kit, $kit->offset($consumerUri, strpos($consumer, 'app.mailer') + 1)];
    }
}
