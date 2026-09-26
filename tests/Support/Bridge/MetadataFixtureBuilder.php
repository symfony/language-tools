<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

use Symfony\Lsp\Tests\Support\TestWorkspace;

final class MetadataFixtureBuilder
{
    public function __construct(
        private readonly TestWorkspace $workspace,
        private readonly FakeFrameworkPrelude $prelude = new FakeFrameworkPrelude(),
    ) {
    }

    public function writeFormApplication(): void
    {
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            namespace Symfony\Component\Form;
            interface FormTypeInterface {}
            __CONSOLE_IO__
            namespace App;
            final class Kernel
            {
                public function __construct(string $environment, bool $debug) {}
                public function shutdown(): void {}
            }
            __FRAMEWORK_APPLICATION__
            PHP,
            applicationMembers: <<<'PHP'
    private const DESCRIPTIONS = [
        'App\\Form\\UserType' => [
            'class' => 'App\\Form\\UserType',
            'block_prefix' => 'user',
            'options' => [
                'own' => ['label'],
                'overridden' => [],
                'parent' => [
                    'Symfony\\Component\\Form\\Extension\\Core\\Type\\ChoiceType' => ['multiple'],
                    'Symfony\\Component\\Form\\Extension\\Core\\Type\\FormType' => ['attr'],
                ],
                'extension' => [],
                'required' => ['label'],
            ],
            'parent_types' => [
                'Symfony\\Component\\Form\\Extension\\Core\\Type\\ChoiceType',
                'Symfony\\Component\\Form\\Extension\\Core\\Type\\FormType',
            ],
        ],
        'Symfony\\Component\\Form\\Extension\\Core\\Type\\ChoiceType' => [
            'class' => 'Symfony\\Component\\Form\\Extension\\Core\\Type\\ChoiceType',
            'block_prefix' => 'choice',
            'options' => [
                'own' => ['multiple'],
                'overridden' => [],
                'parent' => ['Symfony\\Component\\Form\\Extension\\Core\\Type\\FormType' => ['attr']],
                'extension' => ['App\\Form\\Extension\\EnhancedChoiceTypeExtension' => ['selectpicker', 'width']],
                'required' => [],
            ],
            'parent_types' => ['Symfony\\Component\\Form\\Extension\\Core\\Type\\FormType'],
        ],
        'Symfony\\Component\\Form\\Extension\\Core\\Type\\FormType' => [
            'class' => 'Symfony\\Component\\Form\\Extension\\Core\\Type\\FormType',
            'block_prefix' => 'form',
            'options' => ['own' => ['attr'], 'overridden' => [], 'parent' => [], 'extension' => [], 'required' => []],
            'parent_types' => [],
        ],
    ];

    public function has(string $command): bool { return 'debug:form' === $command; }

    public function run(object $input, object $output): int
    {
        if ('debug:form' !== $input->arguments['command']) {
            return 1;
        }
        $class = $input->arguments['class'] ?? null;
        if (null === $class) {
            $output->write(json_encode([
                'builtin_form_types' => [
                    'Symfony\\Component\\Form\\Extension\\Core\\Type\\FormType',
                    'Symfony\\Component\\Form\\Extension\\Core\\Type\\ChoiceType',
                ],
                'service_form_types' => ['App\\Form\\UserType', 'App\\Form\\UnknownType'],
            ], JSON_THROW_ON_ERROR));

            return 0;
        }
        if (!isset(self::DESCRIPTIONS[$class])) {
            return 1;
        }
        $output->write(json_encode(self::DESCRIPTIONS[$class], JSON_THROW_ON_ERROR));

        return 0;
    }
PHP,
        ));
    }

    public function writeConstraintApplication(): void
    {
        $this->workspace->write('vendor/symfony/validator/Constraints/Alpha.php', <<<'PHP'
            <?php
            namespace Symfony\Component\Validator\Constraints;
            final class Alpha extends \Symfony\Component\Validator\Constraint
            {
                public function __construct(public ?int $min = null) {}
            }
            PHP);
        $this->workspace->write('vendor/symfony/validator/Constraints/ExpressionLanguageProvider.php', <<<'PHP'
            <?php
            namespace Symfony\Component\Validator\Constraints;
            final class ExpressionLanguageProvider implements \Missing\OptionalInterface
            {
            }
            PHP);
        $this->workspace->write('vendor/symfony/validator/Constraints/Zulu.php', <<<'PHP'
            <?php
            namespace Symfony\Component\Validator\Constraints;
            final class Zulu extends \Symfony\Component\Validator\Constraint
            {
                public function __construct(public ?int $max = null) {}
            }
            PHP);
        $this->workspace->write('vendor/autoload.php', $this->prelude->render(<<<'PHP'
            __INSTALLED_VERSIONS__
            namespace Symfony\Component\Filesystem;
            final class Path
            {
                public static function join(string $root, string $path): string { return rtrim($root, '/\\').'/'.ltrim($path, '/\\'); }
            }
            namespace Symfony\Component\Validator;
            abstract class Constraint
            {
            }
            \spl_autoload_register(static function (string $class): void {
                $prefix = 'Symfony\\Component\\Validator\\Constraints\\';
                if (!str_starts_with($class, $prefix)) {
                    return;
                }
                $path = __DIR__.'/symfony/validator/Constraints/'.substr($class, strlen($prefix)).'.php';
                if (is_file($path)) {
                    require $path;
                }
            });
            PHP));
    }
}
