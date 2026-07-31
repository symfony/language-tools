<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class EnvironmentExtractor
{
    public function __construct(private readonly PositionConverter $converter)
    {
    }

    public function extract(string $uri, string $languageId, string $text): EnvironmentSourceFacts
    {
        $declarations = [];
        $references = [];
        $path = rawurldecode((string) parse_url($uri, \PHP_URL_PATH));
        if ('dotenv' === $languageId || str_starts_with(basename($path), '.env')) {
            preg_match_all('/^(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=(.*)$/m', $text, $matches, \PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$name, $offset]) {
                $declarations[] = new EnvironmentDeclaration(
                    $name,
                    $uri,
                    $this->range($text, $offset, \strlen($name)),
                    true,
                );
            }
            preg_match_all('/(?<!\\\\)\$(?:\{([A-Za-z_][A-Za-z0-9_]*)(?:[^}]*)\}|([A-Za-z_][A-Za-z0-9_]*))/', $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $capture = '' !== ($match[1][0] ?? '') ? $match[1] : ($match[2] ?? null);
                if (null === $capture) {
                    continue;
                }
                [$name, $offset] = $capture;
                $references[] = new EnvironmentReference($name, $uri, $this->range($text, $offset, \strlen($name)), []);
            }
        }
        if (\in_array($languageId, ['php', 'twig', 'yaml'], true)) {
            preg_match_all('/%env\(([^)%]+)\)%/', $text, $matches, \PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$expression, $offset]) {
                $parts = explode(':', $expression);
                $name = array_pop($parts);
                if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                    continue;
                }
                $nameOffset = $offset + \strlen($expression) - \strlen($name);
                $references[] = new EnvironmentReference(
                    $name,
                    $uri,
                    $this->range($text, $nameOffset, \strlen($name)),
                    $parts,
                );
            }
        }

        return new EnvironmentSourceFacts($uri, $declarations, $references);
    }

    private function range(string $text, int $offset, int $length): Range
    {
        return new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + $length));
    }
}
