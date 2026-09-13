<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Parser\JavaScript\JavaScriptTokenizer;

/**
 * Analyzes controller files the runtime bridge points at, including files outside
 * the indexed project scope such as installed Symfony UX packages.
 */
final class StimulusControllerSourceLoader
{
    private const MAXIMUM_SIZE = 1_048_576;

    /** @var array<string, array{int, int, StimulusControllerSource}> */
    private array $sources = [];

    public function __construct(
        private readonly JavaScriptTokenizer $tokenizer,
        private readonly StimulusControllerSourceAnalyzer $analyzer,
    ) {
    }

    /** @phpstan-impure */
    public function load(string $path): ?StimulusControllerSource
    {
        $modifiedAt = @filemtime($path);
        $size = @filesize($path);
        if (false === $modifiedAt || false === $size || $size > self::MAXIMUM_SIZE) {
            unset($this->sources[$path]);

            return null;
        }
        $cached = $this->sources[$path] ?? null;
        if (null !== $cached && $modifiedAt === $cached[0] && $size === $cached[1]) {
            return $cached[2];
        }
        $text = @file_get_contents($path);
        if (false === $text) {
            unset($this->sources[$path]);

            return null;
        }
        $source = $this->analyzer->analyze($text, $this->tokenizer->tokenize($text));
        $this->sources[$path] = [$modifiedAt, $size, $source];

        return $source;
    }
}
