<?php

namespace Symfony\Lsp\Tests\Index;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Index\PhpRuntimeStructureHasher;

final class PhpRuntimeStructureHasherTest extends TestCase
{
    #[DataProvider('ordinarySourceProvider')]
    public function testIgnoresOrdinaryMethodBodies(string $path): void
    {
        $hasher = new PhpRuntimeStructureHasher();
        $original = '<?php final class Service { public function value(): int { return 1; } }';
        $changed = '<?php final class Service { public function value(): int { return 2; } }';

        self::assertSame($hasher->hash($path, $original), $hasher->hash($path, $changed));
    }

    /** @return iterable<string, array{string}> */
    public static function ordinarySourceProvider(): iterable
    {
        yield 'controller' => ['src/Controller/ArticleController.php'];
        yield 'repository' => ['src/Repository/ArticleRepository.php'];
        yield 'message handler' => ['src/MessageHandler/CreateArticleHandler.php'];
        yield 'command' => ['src/Command/CreateArticleCommand.php'];
        yield 'entity' => ['src/Entity/Article.php'];
        yield 'service' => ['src/Service.php'];
    }

    public function testDetectsDeclarationChanges(): void
    {
        $hasher = new PhpRuntimeStructureHasher();
        $original = '<?php final class Service { public function value(): int { return 1; } }';
        $changed = '<?php final class Service { #[AutowireMethodOf] public function value(string $name): int { return 1; } }';

        self::assertNotSame($hasher->hash('src/Service.php', $original), $hasher->hash('src/Service.php', $changed));
    }

    #[DataProvider('executedSourceProvider')]
    public function testPreservesBodiesThatCanRunWhileBuildingRuntimeMetadata(string $path, string $declaration): void
    {
        $hasher = new PhpRuntimeStructureHasher();
        $original = '<?php '.$declaration.' { public function configure(): int { return 1; } }';
        $changed = str_replace('return 1;', 'return 2;', $original);

        self::assertNotSame($hasher->hash($path, $original), $hasher->hash($path, $changed));
    }

    /** @return iterable<string, array{string, string}> */
    public static function executedSourceProvider(): iterable
    {
        yield 'PHP configuration' => ['config/services.php', 'return new class'];
        yield 'kernel' => ['src/Kernel.php', 'final class Kernel'];
        yield 'dependency injection extension' => ['src/DependencyInjection/AppExtension.php', 'final class AppExtension'];
        yield 'event subscriber' => ['src/EventSubscriber/AppSubscriber.php', 'final class AppSubscriber'];
        yield 'form type' => ['src/Form/AppType.php', 'final class AppType'];
        yield 'Twig extension' => ['src/Twig/AppExtension.php', 'final class AppExtension'];
        yield 'validator' => ['src/Validator/AppValidator.php', 'final class AppValidator'];
        yield 'compiler pass' => ['src/Compiler.php', 'final class Compiler implements CompilerPassInterface'];
        yield 'bundle' => ['src/AppBundle.php', 'final class AppBundle extends Bundle'];
        yield 'PHP attribute' => ['src/Attribute/AppAttribute.php', '#[Attribute] final class AppAttribute'];
    }

    public function testIgnoresNonPhpFiles(): void
    {
        self::assertNull((new PhpRuntimeStructureHasher())->hash('templates/index.html.twig', '{{ value }}'));
    }
}
