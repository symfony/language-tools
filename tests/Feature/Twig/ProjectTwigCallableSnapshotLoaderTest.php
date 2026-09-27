<?php

namespace Symfony\Lsp\Tests\Feature\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Twig\ProjectTwigCallableSnapshotLoader;
use Symfony\Lsp\Feature\Twig\TwigCallableKind;
use Symfony\Lsp\Feature\Twig\TwigCallableRuntimeIndexRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectAnalysisSettings;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Tests\Support\RuntimeSettings;
use Symfony\Lsp\Tests\Support\SnapshotSections;

final class ProjectTwigCallableSnapshotLoaderTest extends TestCase
{
    public function testLoadsRuntimeCallablesByKindAndName(): void
    {
        $indexes = new TwigCallableRuntimeIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace');

        (new ProjectTwigCallableSnapshotLoader($indexes, new UriToPathConverter()))->load($project, SnapshotSections::of($project, [
            'callables' => [
                [
                    'kind' => 'function',
                    'name' => 'css',
                    'class' => 'Symfony\UX\Css\Twig\CssRuntime',
                    'method' => 'css',
                    'declaringClass' => 'Symfony\UX\Css\Twig\CssRuntime',
                    'file' => '/workspace/vendor/symfony/ux-css/src/Twig/CssRuntime.php',
                    'needsEnvironment' => true,
                    'needsContext' => true,
                    'variadic' => true,
                ],
                [
                    'kind' => 'filter',
                    'name' => 'css',
                    'class' => 'App\Twig\AppExtension',
                    'method' => 'shout',
                    'declaringClass' => 'App\Twig\ShoutTrait',
                    'file' => '/workspace/src/Twig/ShoutTrait.php',
                    'needsCharset' => true,
                    'needsIsSandboxed' => true,
                ],
                ['kind' => 'test', 'name' => 'even', 'class' => 'App\Even', 'method' => 'even', 'declaringClass' => 'App\Even', 'file' => '/workspace/src/Even.php'],
                ['kind' => 'function', 'name' => 'broken', 'class' => 'App\Broken', 'method' => 'broken', 'declaringClass' => 'App\Broken', 'file' => null],
                'invalid',
            ],
        ]));

        $index = $indexes->forProject($project);
        $functions = $index->declarations(TwigCallableKind::Function, 'css');
        self::assertCount(1, $functions);
        self::assertSame(TwigCallableKind::Function, $functions[0]->kind);
        self::assertSame('css', $functions[0]->name);
        self::assertSame('Symfony\UX\Css\Twig\CssRuntime', $functions[0]->className);
        self::assertSame('css', $functions[0]->method);
        self::assertSame('Symfony\UX\Css\Twig\CssRuntime', $functions[0]->declaringClassName);
        self::assertSame('file:///workspace/vendor/symfony/ux-css/src/Twig/CssRuntime.php', $functions[0]->uri);
        self::assertFalse($functions[0]->needsCharset);
        self::assertTrue($functions[0]->needsEnvironment);
        self::assertTrue($functions[0]->needsContext);
        self::assertFalse($functions[0]->needsIsSandboxed);
        self::assertTrue($functions[0]->variadic);

        $filters = $index->declarations(TwigCallableKind::Filter, 'css');
        self::assertCount(1, $filters);
        self::assertSame('App\Twig\AppExtension', $filters[0]->className);
        self::assertSame('App\Twig\ShoutTrait', $filters[0]->declaringClassName);
        self::assertSame('file:///workspace/src/Twig/ShoutTrait.php', $filters[0]->uri);
        self::assertTrue($filters[0]->needsCharset);
        self::assertFalse($filters[0]->needsEnvironment);
        self::assertFalse($filters[0]->needsContext);
        self::assertTrue($filters[0]->needsIsSandboxed);
        self::assertFalse($filters[0]->variadic);

        self::assertSame([], $index->declarations(TwigCallableKind::Function, 'even'));
        self::assertSame([], $index->declarations(TwigCallableKind::Function, 'broken'));
        self::assertSame([], $index->declarations(TwigCallableKind::Function, 'CSS'));
    }

    public function testMapsContainerPathsBackToTheHost(): void
    {
        $indexes = new TwigCallableRuntimeIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $configuration = RuntimeSettings::configuration(new ProjectAnalysisSettings(containerProjectRoot: '/app'));

        (new ProjectTwigCallableSnapshotLoader($indexes, new UriToPathConverter()))->load($project, SnapshotSections::of($project, [
            'callables' => [
                [
                    'kind' => 'function',
                    'name' => 'css',
                    'class' => 'Symfony\UX\Css\Twig\CssRuntime',
                    'method' => 'css',
                    'declaringClass' => 'Symfony\UX\Css\Twig\CssRuntime',
                    'file' => '/app/vendor/symfony/ux-css/src/Twig/CssRuntime.php',
                ],
            ],
        ], $configuration));

        $declarations = $indexes->forProject($project)->declarations(TwigCallableKind::Function, 'css');
        self::assertCount(1, $declarations);
        self::assertSame('file:///workspace/vendor/symfony/ux-css/src/Twig/CssRuntime.php', $declarations[0]->uri);
    }

    public function testReplacesPreviouslyLoadedCallables(): void
    {
        $indexes = new TwigCallableRuntimeIndexRegistry();
        $project = new Project('/workspace', 'file:///workspace');
        $loader = new ProjectTwigCallableSnapshotLoader($indexes, new UriToPathConverter());
        $loader->load($project, SnapshotSections::of($project, [
            'callables' => [
                ['kind' => 'function', 'name' => 'stale', 'class' => 'App\Stale', 'method' => 'stale', 'declaringClass' => 'App\Stale', 'file' => '/workspace/src/Stale.php'],
            ],
        ]));

        $loader->load($project, SnapshotSections::of($project, [
            'callables' => [
                ['kind' => 'function', 'name' => 'fresh', 'class' => 'App\Fresh', 'method' => 'fresh', 'declaringClass' => 'App\Fresh', 'file' => '/workspace/src/Fresh.php'],
            ],
        ]));

        $index = $indexes->forProject($project);
        self::assertSame([], $index->declarations(TwigCallableKind::Function, 'stale'));
        self::assertCount(1, $index->declarations(TwigCallableKind::Function, 'fresh'));
    }
}
