<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathPolicy;
use Symfony\Lsp\Project\ProjectPathResolver;

final class StimulusControllerExtractor
{
    private const LAZY_COMMENT_PATTERN = '/\/\*!?\s*stimulusFetch:\s*[\'"]lazy[\'"]\s*\*\/|\/\/\s*stimulusFetch:\s*[\'"]lazy[\'"]/i';
    private const LIFECYCLE_METHODS = ['connect', 'constructor', 'disconnect', 'initialize'];
    private const APPLICATION_FACTORY_PATTERN = '/(?:^|[^.\w$])([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(?:await\s+)?(?:startStimulusApp|Application\s*\.\s*start)\s*\(/';
    private const REGISTRATION_PATTERN = '/(?:^|[^.\w$])((?:this\s*\.\s*)?[A-Za-z_$][A-Za-z0-9_$]*)\s*\.\s*register\s*\(\s*([\'"])([^\'"]+)\2\s*,/';
    private const APPLICATION_IDENTIFIERS = ['application', 'this.application'];

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly ProjectPathResolver $pathResolver,
        private readonly JavaScriptSourceAnalyzer $codeMasker,
        private readonly StimulusControllerNameNormalizer $controllerNameNormalizer,
    ) {
    }

    /** @return list<StimulusControllerDeclaration> */
    public function extract(Project $project, string $uri, string $text): array
    {
        $code = $this->codeMasker->mask($text);
        $name = $this->controllerName($project, $uri);
        if (null === $name) {
            return $this->registrations($project, $uri, $text, $code);
        }

        $members = [];
        $declarationOffset = 0;
        $declarationLength = 0;
        if (null !== $class = $this->exportedClass($code)) {
            [$declarationOffset, $declarationLength, $bodyOffset, $bodyLength] = $class;
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

        return [
            new StimulusControllerDeclaration(
                $name,
                $uri,
                $this->converter->toRange($text, $declarationOffset, $declarationLength),
                $members,
                1 === preg_match(self::LAZY_COMMENT_PATTERN, $text),
            ),
            ...$this->registrations($project, $uri, $text, $code),
        ];
    }

    /** @return list<StimulusControllerDeclaration> */
    private function registrations(Project $project, string $uri, string $text, string $code): array
    {
        if (!$this->isAssetFile($project, $uri) || !preg_match_all(self::REGISTRATION_PATTERN, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $applications = $this->applicationIdentifiers($text, $code);
        $declarations = [];
        foreach ($matches as $match) {
            [$receiver, $receiverOffset] = $match[1];
            [$name, $nameOffset] = $match[3];
            if (' ' === $code[$receiverOffset] || !\in_array(str_replace([' ', "\t", "\r", "\n"], '', $receiver), $applications, true)) {
                continue;
            }
            $declarations[] = new StimulusControllerDeclaration(
                $name,
                $uri,
                $this->converter->toRange($text, $nameOffset, \strlen($name)),
                [],
                false,
            );
        }

        return $declarations;
    }

    /** @return list<string> */
    private function applicationIdentifiers(string $text, string $code): array
    {
        $identifiers = self::APPLICATION_IDENTIFIERS;
        preg_match_all(self::APPLICATION_FACTORY_PATTERN, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            [$identifier, $offset] = $match[1];
            if (' ' !== $code[$offset]) {
                $identifiers[] = $identifier;
            }
        }

        return $identifiers;
    }

    /** @return array{int, int, int, int}|null */
    private function exportedClass(string $code): ?array
    {
        if (!preg_match('/\bexport\s+default\s+(?:abstract\s+)?class\b/', $code, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $declaration = $match[0][0];
        $declarationOffset = $match[0][1];
        $open = strpos($code, '{', $declarationOffset + \strlen($declaration));
        if (false === $open) {
            return [$declarationOffset, \strlen($declaration), \strlen($code), 0];
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
        if (null === $path || !preg_match('#^assets/(?:[^/]+/)*?controllers/(.*?)(?:_|-)controller\.[jt]s$#', $path, $match)) {
            return null;
        }
        if ([] !== array_intersect(explode('/', $path), ProjectPathPolicy::EXCLUDED_DIRECTORIES)) {
            return null;
        }

        return $this->controllerNameNormalizer->normalize($match[1]);
    }

    private function isAssetFile(Project $project, string $uri): bool
    {
        $path = $this->pathResolver->relative($project, $uri);
        if (null === $path) {
            return false;
        }
        $segments = explode('/', $path);
        array_pop($segments);

        return \in_array('assets', $segments, true) && [] === array_intersect($segments, ProjectPathPolicy::EXCLUDED_DIRECTORIES);
    }
}
