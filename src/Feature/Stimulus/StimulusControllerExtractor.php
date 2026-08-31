<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;

final class StimulusControllerExtractor
{
    private const LAZY_COMMENT_PATTERN = '/(?:\/\*!?\s*stimulusFetch:\s*[\'"]lazy[\'"]\s*\*\/|\/\/\s*stimulusFetch:\s*[\'"]lazy[\'"])\s*(?:export\s+(?:default\s+)?)?(?:abstract\s+)?class\b/i';
    private const LIFECYCLE_METHODS = ['connect', 'constructor', 'disconnect', 'initialize'];

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly ProjectPathResolver $pathResolver,
    ) {
    }

    /** @return list<StimulusControllerDeclaration> */
    public function extract(Project $project, string $uri, string $text): array
    {
        $name = $this->controllerName($project, $uri);
        if (null === $name) {
            return [];
        }

        $members = [];
        $declarationOffset = 0;
        $declarationLength = 0;
        if (null !== $class = $this->exportedClass($text)) {
            [$declarationOffset, $declarationLength, $bodyOffset, $bodyLength] = $class;
            $body = substr($text, $bodyOffset, $bodyLength);
            $members = $this->methodMembers($text, $body, $bodyOffset);
            foreach ([
                'targets' => StimulusMemberKind::Target,
                'outlets' => StimulusMemberKind::Outlet,
                'classes' => StimulusMemberKind::ClassName,
            ] as $property => $kind) {
                array_push($members, ...$this->stringArrayMembers($text, $body, $bodyOffset, $property, $kind));
            }
            array_push($members, ...$this->valueMembers($text, $body, $bodyOffset));
            usort($members, fn (StimulusMember $a, StimulusMember $b): int => $this->converter->toByteOffset($text, $a->range->start) <=> $this->converter->toByteOffset($text, $b->range->start));
        }

        return [new StimulusControllerDeclaration(
            $name,
            $uri,
            $this->converter->toRange($text, $declarationOffset, $declarationLength),
            $members,
            1 === preg_match(self::LAZY_COMMENT_PATTERN, $text),
        )];
    }

    /** @return array{int, int, int, int}|null */
    private function exportedClass(string $text): ?array
    {
        $code = $this->maskNonCode($text);
        if (!preg_match('/\bexport\s+default\s+(?:abstract\s+)?class\b/', $code, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $declaration = $match[0][0];
        $declarationOffset = $match[0][1];
        $open = strpos($code, '{', $declarationOffset + \strlen($declaration));
        if (false === $open) {
            return [$declarationOffset, \strlen($declaration), \strlen($text), 0];
        }

        $depth = 0;
        $length = \strlen($code);
        for ($offset = $open; $offset < $length; ++$offset) {
            if ('{' === $code[$offset]) {
                ++$depth;
            } elseif ('}' === $code[$offset] && 0 === --$depth) {
                return [$declarationOffset, \strlen($declaration), $open + 1, $offset - $open - 1];
            }
        }

        return [$declarationOffset, \strlen($declaration), $open + 1, $length - $open - 1];
    }

    private function maskNonCode(string $text): string
    {
        $masked = $text;
        $length = \strlen($text);
        $state = 'code';
        $quote = null;

        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $text[$offset];
            if ('code' === $state) {
                if ('//' === substr($text, $offset, 2)) {
                    $this->maskByte($masked, $text, $offset);
                    $this->maskByte($masked, $text, ++$offset);
                    $state = 'line_comment';
                } elseif ('/*' === substr($text, $offset, 2)) {
                    $this->maskByte($masked, $text, $offset);
                    $this->maskByte($masked, $text, ++$offset);
                    $state = 'block_comment';
                } elseif ('\'' === $character || '"' === $character || '`' === $character) {
                    $quote = $character;
                    $this->maskByte($masked, $text, $offset);
                    $state = 'string';
                }
                continue;
            }

            if ('line_comment' === $state) {
                if ("\r" === $character || "\n" === $character) {
                    $state = 'code';
                } else {
                    $this->maskByte($masked, $text, $offset);
                }
                continue;
            }

            $this->maskByte($masked, $text, $offset);
            if ('block_comment' === $state) {
                if ('*/' === substr($text, $offset, 2)) {
                    $this->maskByte($masked, $text, ++$offset);
                    $state = 'code';
                }
                continue;
            }

            if ('\\' === $character) {
                if (++$offset < $length) {
                    $this->maskByte($masked, $text, $offset);
                }
            } elseif ($character === $quote) {
                $quote = null;
                $state = 'code';
            }
        }

        return $masked;
    }

    private function maskByte(string &$masked, string $text, int $offset): void
    {
        if ("\r" !== $text[$offset] && "\n" !== $text[$offset] && \ord($text[$offset]) < 0x80) {
            $masked[$offset] = ' ';
        }
    }

    /** @return list<StimulusMember> */
    private function methodMembers(string $text, string $body, int $bodyOffset): array
    {
        preg_match_all('/^[ \t]*(?:async\s+)?([A-Za-z_$][A-Za-z0-9_$]*)\s*\([^)]*\)\s*(?::\s*[^\{\r\n]+)?\s*\{/m', $body, $matches, \PREG_OFFSET_CAPTURE);
        $members = [];
        foreach ($matches[1] as [$name, $offset]) {
            if (!\in_array($name, self::LIFECYCLE_METHODS, true)) {
                $members[] = new StimulusMember($name, StimulusMemberKind::Action, $this->converter->toRange($text, $bodyOffset + $offset, \strlen($name)));
            }
        }

        return $members;
    }

    /** @return list<StimulusMember> */
    private function stringArrayMembers(string $text, string $body, int $bodyOffset, string $property, StimulusMemberKind $kind): array
    {
        if (!preg_match('/\bstatic\s+'.preg_quote($property, '/').'\s*=\s*\[(.*?)\]/s', $body, $match, \PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $valuesBody = $match[1][0];
        $valuesOffset = $bodyOffset + $match[1][1];
        preg_match_all('/([\'"])([^\'"]+)\1/', $valuesBody, $values, \PREG_OFFSET_CAPTURE);
        $members = [];
        foreach ($values[2] as [$name, $offset]) {
            $members[] = new StimulusMember($name, $kind, $this->converter->toRange($text, $valuesOffset + $offset, \strlen($name)));
        }

        return $members;
    }

    /** @return list<StimulusMember> */
    private function valueMembers(string $text, string $body, int $bodyOffset): array
    {
        if (!preg_match('/\bstatic\s+values\s*=\s*\{(.*?)\}/s', $body, $match, \PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $valuesBody = $match[1][0];
        $valuesOffset = $bodyOffset + $match[1][1];
        preg_match_all('/(?:^|,)\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*:/m', $valuesBody, $values, \PREG_OFFSET_CAPTURE);
        $members = [];
        foreach ($values[1] as [$name, $offset]) {
            $members[] = new StimulusMember($name, StimulusMemberKind::Value, $this->converter->toRange($text, $valuesOffset + $offset, \strlen($name)));
        }

        return $members;
    }

    private function controllerName(Project $project, string $uri): ?string
    {
        $path = $this->pathResolver->relative($project, $uri);
        if (null === $path || !str_starts_with($path, 'assets/controllers/')) {
            return null;
        }
        $relative = substr($path, \strlen('assets/controllers/'));
        if (!preg_match('/^(.*?)(?:_|-)controller\.[jt]s$/', $relative, $match)) {
            return null;
        }

        return str_replace(['_', '/'], ['-', '--'], $match[1]);
    }
}
