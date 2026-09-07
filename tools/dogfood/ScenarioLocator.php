<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Symfony\Lsp\Document\DocumentPositionMap;
use Symfony\Lsp\Project\UriToPathConverter;

/**
 * Resolves the source anchor of a scenario inside a provisioned project clone.
 *
 * Anchors are matched against the checked out sources, so a scenario fails
 * loudly as soon as the pinned sources drift away from the reviewed manifest.
 */
final class ScenarioLocator
{
    private const EXCLUDED_SEGMENTS = ['.git', 'node_modules', 'var', 'vendor'];

    private const LANGUAGE_IDS = [
        'ini' => 'ini',
        'js' => 'javascript',
        'json' => 'json',
        'mjs' => 'javascript',
        'php' => 'php',
        'ts' => 'typescript',
        'twig' => 'twig',
        'xlf' => 'xml',
        'xliff' => 'xml',
        'xml' => 'xml',
        'yaml' => 'yaml',
        'yml' => 'yaml',
    ];

    public function __construct(
        private readonly UriToPathConverter $uris = new UriToPathConverter(),
    ) {
    }

    /**
     * @param array<string, mixed>  $scenario a scenario, or a scenario edit merged into it
     * @param array<string, string> $overlays in-memory document texts, keyed by URI
     */
    public function locate(string $projectRoot, array $scenario, array $overlays = []): ScenarioDocument
    {
        $root = realpath($projectRoot);
        if (false === $root || !is_dir($root)) {
            throw new ConfigurationException(\sprintf('The project root "%s" does not exist.', $projectRoot));
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        [$file, $path] = $this->file($root, $scenario['file'] ?? null);
        $languageId = $this->languageId($file);
        $uri = $this->uris->toUri($path);
        $text = $this->text($uri, $path, $file, $overlays);
        $anchor = $this->anchor($scenario['anchor'] ?? null, $file);
        $anchorOffset = $this->anchorOffset($text, $anchor, $file);
        $byteOffset = $anchorOffset + $this->offset($scenario['offset'] ?? 0, $anchor, $file);
        if (0x80 === (\ord($text[$byteOffset] ?? "\0") & 0xC0)) {
            throw new ConfigurationException(\sprintf('The anchor offset of "%s" falls inside a character.', $file));
        }

        return new ScenarioDocument(
            $file,
            $uri,
            $text,
            $languageId,
            (new DocumentPositionMap($text, 'utf-16'))->toPosition($byteOffset),
            $anchorOffset,
            $byteOffset,
        );
    }

    /**
     * @return array{string, string} the project-relative path and the absolute path
     */
    private function file(string $root, mixed $file): array
    {
        if (!\is_string($file) || '' === $file || str_contains($file, "\0") || !mb_check_encoding($file, 'UTF-8')
            || 1 === preg_match('{^[/~]|^[A-Za-z]:|\\\\|://|(?:^|/)\.\.?(?:/|$)|//|/$}', $file)
        ) {
            throw new ConfigurationException(\sprintf('The scenario file "%s" must be a relative path inside the project.', \is_string($file) ? $file : get_debug_type($file)));
        }
        $path = realpath($root.'/'.$file);
        if (false === $path) {
            throw new ConfigurationException(\sprintf('The scenario file "%s" does not exist in "%s".', $file, $root));
        }
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $root.'/')) {
            throw new ConfigurationException(\sprintf('The scenario file "%s" resolves outside of "%s".', $file, $root));
        }
        if (!is_file($path)) {
            throw new ConfigurationException(\sprintf('The scenario file "%s" is not a file.', $file));
        }
        $relative = substr($path, \strlen($root) + 1);
        foreach ([$file, $relative] as $candidate) {
            foreach (explode('/', $candidate) as $segment) {
                if (\in_array($segment, self::EXCLUDED_SEGMENTS, true)) {
                    throw new ConfigurationException(\sprintf('The scenario file "%s" resolves into the excluded directory "%s".', $file, $segment));
                }
            }
        }

        return [$relative, $path];
    }

    /**
     * @param array<string, string> $overlays
     */
    private function text(string $uri, string $path, string $file, array $overlays): string
    {
        $text = $overlays[$uri] ?? null;
        if (null === $text) {
            $text = file_get_contents($path);
            if (false === $text) {
                throw new ConfigurationException(\sprintf('Unable to read the scenario file "%s".', $file));
            }
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new ConfigurationException(\sprintf('The scenario file "%s" is not valid UTF-8.', $file));
        }

        return $text;
    }

    private function anchor(mixed $anchor, string $file): string
    {
        if (!\is_string($anchor) || '' === $anchor || str_contains($anchor, "\0") || !mb_check_encoding($anchor, 'UTF-8')) {
            throw new ConfigurationException(\sprintf('The anchor of "%s" must be a non-empty UTF-8 source excerpt.', $file));
        }

        return $anchor;
    }

    private function anchorOffset(string $text, string $anchor, string $file): int
    {
        $occurrences = substr_count($text, $anchor);
        if (0 === $occurrences) {
            throw new ConfigurationException(\sprintf('The anchor "%s" no longer appears in "%s".', $anchor, $file));
        }
        if (1 < $occurrences) {
            throw new ConfigurationException(\sprintf('The anchor "%s" appears %d times in "%s".', $anchor, $occurrences, $file));
        }

        return (int) strpos($text, $anchor);
    }

    private function offset(mixed $offset, string $anchor, string $file): int
    {
        if (!\is_int($offset) || 0 > $offset || $offset > \strlen($anchor)) {
            throw new ConfigurationException(\sprintf('The anchor offset of "%s" must be a byte offset within its %d byte anchor.', $file, \strlen($anchor)));
        }

        return $offset;
    }

    private function languageId(string $file): string
    {
        $basename = basename($file);
        if (str_starts_with($basename, '.env')) {
            return 'dotenv';
        }
        $extension = strtolower(pathinfo($basename, \PATHINFO_EXTENSION));
        if (!isset(self::LANGUAGE_IDS[$extension])) {
            throw new ConfigurationException(\sprintf('The scenario file "%s" has no supported language.', $file));
        }

        return self::LANGUAGE_IDS[$extension];
    }
}
