<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

final class MetadataFixtureBuilder
{
    public function __construct(
        private readonly BridgeFixtureWorkspace $workspace,
        private readonly FakeFrameworkPrelude $prelude = new FakeFrameworkPrelude(),
    ) {
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
