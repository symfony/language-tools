<?php

namespace Symfony\Lsp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Tests\Support\Bridge\BridgeFixtureWorkspace;
use Symfony\Lsp\Tests\Support\Bridge\BridgeProcessFixture;
use Symfony\Lsp\Tests\Support\Bridge\RouteFixtureBuilder;
use Symfony\Lsp\Tests\Support\Bridge\TwigComponentFixtureBuilder;

final class BridgeTwigComponentsTest extends TestCase
{
    private BridgeFixtureWorkspace $workspace;
    private BridgeProcessFixture $bridge;

    protected function setUp(): void
    {
        $this->workspace = new BridgeFixtureWorkspace();
        $this->bridge = new BridgeProcessFixture($this->workspace->path);
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testEnumeratesRuntimeTwigComponentNames(): void
    {
        (new TwigComponentFixtureBuilder($this->workspace))->writeTwigComponentApplication();

        $process = $this->bridge->run(['--sections=twig_components']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors']);
        self::assertIsArray($result['sections'] ?? null);
        $section = $result['sections']['twig_components'] ?? null;
        self::assertIsArray($section);
        self::assertTrue($section['complete']);
        self::assertSame(['Alert', 'Form:Input', 'acme:Badge', 'ux:icon'], $section['names']);
        self::assertSame(['ux:icon'], $section['caseInsensitiveNames']);
        self::assertSame('components', $section['anonymousTemplateDirectory']);
        self::assertSame([], $section['warnings']);
    }

    public function testReportsIncompleteTwigComponentNamesInsteadOfGuessing(): void
    {
        (new TwigComponentFixtureBuilder($this->workspace))->writeTwigComponentApplication(withUnnameableComponent: true);

        $process = $this->bridge->run(['--sections=twig_components']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertIsArray($result['sections'] ?? null);
        $section = $result['sections']['twig_components'] ?? null;
        self::assertIsArray($section);
        self::assertFalse($section['complete']);
        self::assertSame(['Alert', 'Form:Input', 'acme:Badge', 'ux:icon'], $section['names']);
    }

    public function testClearsTheTwigComponentsSectionWithoutTheComponentPackage(): void
    {
        (new RouteFixtureBuilder($this->workspace))->writeRouteApplication();

        $process = $this->bridge->run(['--sections=twig_components']);

        self::assertSame(0, $process->exitCode, $process->stderr."\n".$process->stdout);
        $result = $process->snapshot;
        self::assertIsArray($result);
        self::assertSame([], $result['errors']);
        $sections = $result['sections'] ?? [];
        self::assertIsArray($sections);
        $section = $sections['twig_components'] ?? null;
        self::assertIsArray($section);
        self::assertSame([
            'complete' => true,
            'names' => [],
            'caseInsensitiveNames' => [],
            'anonymousTemplateDirectory' => 'components',
            'warnings' => [],
        ], array_diff_key($section, ['generation' => true]));
    }
}
