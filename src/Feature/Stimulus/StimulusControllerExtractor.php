<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;

final class StimulusControllerExtractor
{
    private const LAZY_COMMENT_PATTERN = '/\/\*!?\s*stimulusFetch:\s*[\'"]lazy[\'"]\s*\*\/|\/\/\s*stimulusFetch:\s*[\'"]lazy[\'"]/i';
    private const LIFECYCLE_METHODS = ['connect', 'constructor', 'disconnect', 'initialize'];

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly ProjectPathResolver $pathResolver,
        private readonly JavaScriptSourceAnalyzer $codeMasker,
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
            [$declarationOffset, $declarationLength, $bodyOffset, $bodyLength, $code] = $class;
            $body = substr($text, $bodyOffset, $bodyLength);
            $bodyCode = substr($code, $bodyOffset, $bodyLength);
            $members = $this->methodMembers($text, $body, $bodyCode, $bodyOffset);
            foreach ([
                'targets' => StimulusMemberKind::Target,
                'outlets' => StimulusMemberKind::Outlet,
                'classes' => StimulusMemberKind::ClassName,
            ] as $property => $kind) {
                array_push($members, ...$this->stringArrayMembers($text, $body, $bodyCode, $bodyOffset, $property, $kind));
            }
            array_push($members, ...$this->valueMembers($text, $bodyCode, $bodyOffset));
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

    /** @return array{int, int, int, int, string}|null */
    private function exportedClass(string $text): ?array
    {
        $code = $this->codeMasker->mask($text);
        if (!preg_match('/\bexport\s+default\s+(?:abstract\s+)?class\b/', $code, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $declaration = $match[0][0];
        $declarationOffset = $match[0][1];
        $open = strpos($code, '{', $declarationOffset + \strlen($declaration));
        if (false === $open) {
            return [$declarationOffset, \strlen($declaration), \strlen($text), 0, $code];
        }

        $depth = 0;
        $length = \strlen($code);
        for ($offset = $open; $offset < $length; ++$offset) {
            if ('{' === $code[$offset]) {
                ++$depth;
            } elseif ('}' === $code[$offset] && 0 === --$depth) {
                return [$declarationOffset, \strlen($declaration), $open + 1, $offset - $open - 1, $code];
            }
        }

        return [$declarationOffset, \strlen($declaration), $open + 1, $length - $open - 1, $code];
    }

    /** @return list<StimulusMember> */
    private function methodMembers(string $text, string $body, string $bodyCode, int $bodyOffset): array
    {
        preg_match_all('/^[ \t]*(?:async\s+)?([A-Za-z_$][A-Za-z0-9_$]*)\s*\([^)]*\)\s*(?::\s*[^\{\r\n]+)?\s*\{/m', $body, $matches, \PREG_OFFSET_CAPTURE);
        $members = [];
        foreach ($matches[1] as [$name, $offset]) {
            if (' ' !== $bodyCode[$offset] && !\in_array($name, self::LIFECYCLE_METHODS, true)) {
                $members[] = new StimulusMember($name, StimulusMemberKind::Action, $this->converter->toRange($text, $bodyOffset + $offset, \strlen($name)));
            }
        }

        return $members;
    }

    /** @return list<StimulusMember> */
    private function stringArrayMembers(string $text, string $body, string $bodyCode, int $bodyOffset, string $property, StimulusMemberKind $kind): array
    {
        if (!preg_match('/\bstatic\s+'.preg_quote($property, '/').'\s*=\s*(\[)/', $bodyCode, $match, \PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $open = $match[1][1];
        $close = $this->closingDelimiter($bodyCode, $open, '[', ']');
        $valuesOffset = $open + 1;
        $valuesBody = substr($body, $valuesOffset, $close - $valuesOffset);
        $members = [];
        foreach ($this->codeMasker->quotedStrings($valuesBody) as [$name, $offset]) {
            $members[] = new StimulusMember($name, $kind, $this->converter->toRange($text, $bodyOffset + $valuesOffset + $offset, \strlen($name)));
        }

        return $members;
    }

    /** @return list<StimulusMember> */
    private function valueMembers(string $text, string $bodyCode, int $bodyOffset): array
    {
        if (!preg_match('/\bstatic\s+values\s*=\s*(\{)/', $bodyCode, $match, \PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $open = $match[1][1];
        $close = $this->closingDelimiter($bodyCode, $open, '{', '}');
        $valuesOffset = $open + 1;
        $valuesBody = substr($bodyCode, $valuesOffset, $close - $valuesOffset);
        preg_match_all('/(?:^|,)\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*:/m', $valuesBody, $values, \PREG_OFFSET_CAPTURE);
        $members = [];
        foreach ($values[1] as [$name, $offset]) {
            $members[] = new StimulusMember($name, StimulusMemberKind::Value, $this->converter->toRange($text, $bodyOffset + $valuesOffset + $offset, \strlen($name)));
        }

        return $members;
    }

    private function closingDelimiter(string $code, int $open, string $openingDelimiter, string $closingDelimiter): int
    {
        $depth = 0;
        for ($offset = $open, $length = \strlen($code); $offset < $length; ++$offset) {
            if ($openingDelimiter === $code[$offset]) {
                ++$depth;
            } elseif ($closingDelimiter === $code[$offset] && 0 === --$depth) {
                return $offset;
            }
        }

        return \strlen($code);
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
