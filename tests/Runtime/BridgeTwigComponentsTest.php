<?php

namespace Symfony\Lsp\Tests\Runtime;

final class BridgeTwigComponentsTest extends AbstractBridgeTestCase
{
    public function testEnumeratesRuntimeTwigComponentNames(): void
    {
        $this->writeTwigComponentApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=twig_components 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
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
        $this->writeTwigComponentApplication(withUnnameableComponent: true);

        exec(\sprintf(
            '%s %s --project=%s --sections=twig_components 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsArray($result['sections'] ?? null);
        $section = $result['sections']['twig_components'] ?? null;
        self::assertIsArray($section);
        self::assertFalse($section['complete']);
        self::assertSame(['Alert', 'Form:Input', 'acme:Badge', 'ux:icon'], $section['names']);
    }

    public function testClearsTheTwigComponentsSectionWithoutTheComponentPackage(): void
    {
        $this->writeRouteApplication();

        exec(\sprintf(
            '%s %s --project=%s --sections=twig_components 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/resources/bridge.php'),
            escapeshellarg($this->temporaryDirectory),
        ), $output, $exitCode);

        $snapshot = implode("\n", $output);
        self::assertSame(0, $exitCode, $snapshot);
        $result = json_decode($snapshot, true, 512, \JSON_THROW_ON_ERROR);
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
