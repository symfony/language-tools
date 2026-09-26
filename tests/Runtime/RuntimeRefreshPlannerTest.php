<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Index\SourceFileChange;
use Symfony\Lsp\Runtime\RuntimeRefreshMode;
use Symfony\Lsp\Runtime\RuntimeRefreshPlan;
use Symfony\Lsp\Runtime\RuntimeRefreshPlanner;

final class RuntimeRefreshPlannerTest extends TestCase
{
    /** @param list<string> $sections */
    #[DataProvider('preservedProvider')]
    public function testPreservesTheContainerForDomainsDescribedWithoutIt(string $path, SourceFileChange $change, array $sections): void
    {
        $plan = (new RuntimeRefreshPlanner())->plan($path, $change);

        self::assertSame(RuntimeRefreshMode::Preserve, $plan->mode());
        self::assertSame($sections, $plan->sections());
        self::assertFalse($plan->refreshesEverySection());
    }

    /** @return iterable<string, array{string, SourceFileChange, list<string>}> */
    public static function preservedProvider(): iterable
    {
        yield 'route attribute' => ['src/Controller.php', SourceFileChange::factsChanged(['route']), ['routes']];
        yield 'route configuration' => ['config/routes.yaml', SourceFileChange::factsChanged(['route']), ['routes']];
        yield 'translation catalog' => ['translations/messages.en.yaml', SourceFileChange::factsChanged(['translation']), ['translations']];
        yield 'asset and Stimulus' => ['assets/app.js', SourceFileChange::factsChanged(['asset', 'stimulus']), ['assets', 'stimulus']];
        yield 'new asset without facts' => ['assets/new_controller.js', SourceFileChange::untracked(), ['assets', 'stimulus']];
        yield 'new translation without facts' => ['app/Bundle/translations/en_US/messages.ini', SourceFileChange::untracked(), ['translations']];
        yield 'new translation in a capitalized catalog directory' => ['app/Bundle/Translations/en_US/messages.ini', SourceFileChange::untracked(), ['translations']];
    }

    /** @param list<string> $sections */
    #[DataProvider('rebuiltProvider')]
    public function testRebuildsTheContainerForDomainsDescribedFromIt(string $path, SourceFileChange $change, array $sections): void
    {
        $plan = (new RuntimeRefreshPlanner())->plan($path, $change);

        self::assertSame(RuntimeRefreshMode::Rebuild, $plan->mode());
        self::assertSame($sections, $plan->sections());
    }

    /** @return iterable<string, array{string, SourceFileChange, list<string>}> */
    public static function rebuiltProvider(): iterable
    {
        yield 'listener' => ['src/Listener.php', SourceFileChange::factsChanged(['event']), ['events', 'container']];
        yield 'XML service' => ['src/Resources/config/services.xml', SourceFileChange::factsChanged(['dependency_injection']), ['container']];
        yield 'Twig extension' => ['src/Twig/AppExtension.php', SourceFileChange::factsChanged(['twig_callable']), ['twig']];
        yield 'component and route' => ['src/Component.php', SourceFileChange::factsChanged(['twig_component', 'route']), ['twig', 'twig_components', 'container', 'routes']];
        yield 'ambiguous configuration' => ['config/packages/framework.yaml', SourceFileChange::factsChanged(['dependency_injection']), []];
        yield 'domain without runtime sections' => ['src/Entity.php', SourceFileChange::factsChanged(['doctrine']), []];
        yield 'unknown domain' => ['composer.lock', SourceFileChange::untracked(), []];
    }

    #[DataProvider('refreshedPathProvider')]
    public function testDecidesWhetherAChangedPathCanMakeRuntimeMetadataStale(string $path, SourceFileChange $change, bool $requiresRefresh): void
    {
        self::assertSame($requiresRefresh, (new RuntimeRefreshPlanner())->requiresRefresh($path, $change));
    }

    /** @return iterable<string, array{string, SourceFileChange, bool}> */
    public static function refreshedPathProvider(): iterable
    {
        yield 'source' => ['src/Controller.php', SourceFileChange::factsChanged(['route']), true];
        yield 'capitalized catalog directory' => ['app/Bundle/Translations/en_US/messages.ini', SourceFileChange::untracked(), true];
        yield 'manifest' => ['composer.json', SourceFileChange::untracked(), true];
        yield 'bundle service definition' => ['src/Resources/config/services.xml', SourceFileChange::untracked(), true];
        yield 'configuration' => ['config/packages/framework.yaml', SourceFileChange::untracked(), true];
        yield 'unchanged source' => ['src/Controller.php', SourceFileChange::unchanged(), false];
        yield 'content-only source change' => ['src/Controller.php', SourceFileChange::contentOnly(), false];
        yield 'ignored source' => ['src/Controller.php', SourceFileChange::ignored(), false];
        yield 'compiled container' => ['var/cache/dev/Container.php', SourceFileChange::factsChanged(['dependency_injection']), false];
        yield 'dependency' => ['vendor/acme/bundle/Extension.php', SourceFileChange::factsChanged(['route']), false];
        yield 'template' => ['templates/article.html.twig', SourceFileChange::factsChanged(['route']), false];
        yield 'unrelated XML' => ['public/sitemap.xml', SourceFileChange::untracked(), false];
    }

    public function testCombinedPlansKeepTheStrongestModeAndEverySection(): void
    {
        $preserved = RuntimeRefreshPlan::preserve(['routes']);

        self::assertSame(RuntimeRefreshMode::Preserve, $preserved->combine(RuntimeRefreshPlan::preserve(['assets', 'routes']))->mode());
        self::assertSame(['routes', 'assets'], $preserved->combine(RuntimeRefreshPlan::preserve(['assets', 'routes']))->sections());
        self::assertSame(RuntimeRefreshMode::Rebuild, $preserved->combine(RuntimeRefreshPlan::rebuild(['container']))->mode());
        self::assertSame(['routes', 'container'], $preserved->combine(RuntimeRefreshPlan::rebuild(['container']))->sections());
        self::assertTrue($preserved->combine(RuntimeRefreshPlan::reuse())->refreshesEverySection());
        self::assertSame(RuntimeRefreshMode::Reuse, $preserved->combine(RuntimeRefreshPlan::reuse())->mode());
    }
}
