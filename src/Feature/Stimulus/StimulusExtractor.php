<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Project\Project;

final class StimulusExtractor
{
    private const LIFECYCLE_METHODS = ['connect', 'constructor', 'disconnect', 'initialize'];

    public function __construct(private readonly PositionConverter $converter)
    {
    }

    public function extract(Project $project, string $uri, string $languageId, string $text): StimulusSourceFacts
    {
        if (\in_array($languageId, ['javascript', 'typescript'], true)) {
            return new StimulusSourceFacts($uri, $this->javascriptDeclarations($project, $uri, $text), $this->javascriptReferences($uri, $text));
        }
        if ('twig' === $languageId) {
            return new StimulusSourceFacts($uri, [], $this->twigReferences($uri, $text));
        }

        return new StimulusSourceFacts($uri, [], []);
    }

    public function completionContext(string $languageId, string $text, int $offset): ?StimulusCompletionContext
    {
        if ('twig' !== $languageId) {
            return null;
        }
        $before = substr($text, 0, $offset);
        if (preg_match('/\bstimulus_(?:action|target)\s*\(\s*([\'"])([^\'"]+)\1\s*,\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            return new StimulusCompletionContext(
                str_contains($match[0], 'stimulus_action') ? StimulusMemberKind::Action : StimulusMemberKind::Target,
                $match[2],
                $match[4],
                $this->range($text, $offset - \strlen($match[4]), \strlen($match[4])),
            );
        }
        if (preg_match('/\bstimulus_(?:controller|action|target)\s*\(\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            return new StimulusCompletionContext(null, null, $match[2], $this->range($text, $offset - \strlen($match[2]), \strlen($match[2])));
        }
        if (preg_match('/\bdata-action\s*=\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            $token = preg_replace('/^.*\s/s', '', $match[2]);
            if (!\is_string($token)) {
                return null;
            }
            $arrow = strrpos($token, '->');
            $descriptor = false === $arrow ? $token : substr($token, $arrow + 2);
            if (false !== $hash = strpos($descriptor, '#')) {
                $controller = substr($descriptor, 0, $hash);
                $prefix = substr($descriptor, $hash + 1);
                if (str_contains($prefix, ':') || str_contains($prefix, '.')) {
                    return null;
                }

                return new StimulusCompletionContext(StimulusMemberKind::Action, $controller, $prefix, $this->range($text, $offset - \strlen($prefix), \strlen($prefix)));
            }

            return new StimulusCompletionContext(null, null, $descriptor, $this->range($text, $offset - \strlen($descriptor), \strlen($descriptor)));
        }
        if (preg_match('/\bdata-controller\s*=\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            $prefix = preg_replace('/^.*\s/s', '', $match[2]);
            if (\is_string($prefix)) {
                return new StimulusCompletionContext(null, null, $prefix, $this->range($text, $offset - \strlen($prefix), \strlen($prefix)));
            }
        }
        if (preg_match('/\bdata-([A-Za-z0-9_@.-]+)-target\s*=\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            $prefix = preg_replace('/^.*\s/s', '', $match[3]);
            if (\is_string($prefix)) {
                return new StimulusCompletionContext(StimulusMemberKind::Target, $match[1], $prefix, $this->range($text, $offset - \strlen($prefix), \strlen($prefix)));
            }
        }

        return null;
    }

    /** @return list<StimulusControllerDeclaration> */
    private function javascriptDeclarations(Project $project, string $uri, string $text): array
    {
        $name = $this->controllerName($project, $uri);
        if (null === $name) {
            return [];
        }
        $members = $this->methodMembers($text);
        foreach ([
            'targets' => StimulusMemberKind::Target,
            'outlets' => StimulusMemberKind::Outlet,
            'classes' => StimulusMemberKind::ClassName,
        ] as $property => $kind) {
            array_push($members, ...$this->stringArrayMembers($text, $property, $kind));
        }
        array_push($members, ...$this->valueMembers($text));
        usort($members, fn (StimulusMember $a, StimulusMember $b): int => $this->converter->toByteOffset($text, $a->range()->start()) <=> $this->converter->toByteOffset($text, $b->range()->start()));
        $offset = preg_match('/\bexport\s+default\s+class\b/', $text, $match, \PREG_OFFSET_CAPTURE) ? $match[0][1] : 0;

        return [new StimulusControllerDeclaration(
            $name,
            $uri,
            $this->range($text, $offset, \strlen('export default class')),
            $members,
            1 === preg_match('/\/\*!?\s*stimulusFetch:\s*\'lazy\'\s*\*\//i', $text),
        )];
    }

    /** @return list<StimulusMember> */
    private function methodMembers(string $text): array
    {
        preg_match_all('/^[ \t]*(?:async\s+)?([A-Za-z_$][A-Za-z0-9_$]*)\s*\([^)]*\)\s*(?::\s*[^\{\r\n]+)?\s*\{/m', $text, $matches, \PREG_OFFSET_CAPTURE);
        $members = [];
        foreach ($matches[1] as [$name, $offset]) {
            if (!\in_array($name, self::LIFECYCLE_METHODS, true)) {
                $members[] = new StimulusMember($name, StimulusMemberKind::Action, $this->range($text, $offset, \strlen($name)));
            }
        }

        return $members;
    }

    /** @return list<StimulusMember> */
    private function stringArrayMembers(string $text, string $property, StimulusMemberKind $kind): array
    {
        if (!preg_match('/\bstatic\s+'.preg_quote($property, '/').'\s*=\s*\[(.*?)\]/s', $text, $match, \PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $body = $match[1][0];
        $bodyOffset = $match[1][1];
        preg_match_all('/([\'"])([^\'"]+)\1/', $body, $values, \PREG_OFFSET_CAPTURE);
        $members = [];
        foreach ($values[2] as [$name, $offset]) {
            $members[] = new StimulusMember($name, $kind, $this->range($text, $bodyOffset + $offset, \strlen($name)));
        }

        return $members;
    }

    /** @return list<StimulusMember> */
    private function valueMembers(string $text): array
    {
        if (!preg_match('/\bstatic\s+values\s*=\s*\{(.*?)\}/s', $text, $match, \PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $body = $match[1][0];
        $bodyOffset = $match[1][1];
        preg_match_all('/(?:^|,)\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*:/m', $body, $values, \PREG_OFFSET_CAPTURE);
        $members = [];
        foreach ($values[1] as [$name, $offset]) {
            $members[] = new StimulusMember($name, StimulusMemberKind::Value, $this->range($text, $bodyOffset + $offset, \strlen($name)));
        }

        return $members;
    }

    /** @return list<StimulusReference> */
    private function javascriptReferences(string $uri, string $text): array
    {
        $references = [];
        foreach ([
            '/\b(?:application|this\.application)\s*\.\s*register\s*\(\s*([\'"])([^\'"]+)\1/',
            '/\b(?:application|this\.application)\s*\.\s*getControllerForElementAndIdentifier\s*\([^,]+,\s*([\'"])([^\'"]+)\1/',
        ] as $pattern) {
            preg_match_all($pattern, $text, $matches, \PREG_OFFSET_CAPTURE);
            foreach ($matches[2] as [$name, $offset]) {
                $references[] = new StimulusReference($name, null, null, $uri, $this->range($text, $offset, \strlen($name)));
            }
        }

        return $references;
    }

    /** @return list<StimulusReference> */
    private function twigReferences(string $uri, string $text): array
    {
        $references = [];
        preg_match_all('/\bdata-controller\s*=\s*([\'"])(.*?)\1/s', $text, $attributes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($attributes as $attribute) {
            $this->appendControllerTokens($references, $uri, $text, $attribute[2][0], $attribute[2][1]);
        }
        preg_match_all('/\bdata-action\s*=\s*([\'"])(.*?)\1/s', $text, $attributes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($attributes as $attribute) {
            $value = $attribute[2][0];
            $valueOffset = $attribute[2][1];
            preg_match_all('/(?:[^\s]+->)?([A-Za-z0-9_@.\/-]+)#([A-Za-z_$][A-Za-z0-9_$]*)/', $value, $actions, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($actions as $action) {
                $controller = $action[1][0];
                $name = $action[2][0];
                $references[] = new StimulusReference($controller, null, null, $uri, $this->range($text, $valueOffset + $action[1][1], \strlen($controller)));
                $references[] = new StimulusReference($controller, StimulusMemberKind::Action, $name, $uri, $this->range($text, $valueOffset + $action[2][1], \strlen($name)));
            }
        }
        preg_match_all('/\bdata-([A-Za-z0-9_@.-]+)-target\s*=\s*([\'"])(.*?)\2/s', $text, $attributes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($attributes as $attribute) {
            $controller = $attribute[1][0];
            $value = $attribute[3][0];
            $valueOffset = $attribute[3][1];
            preg_match_all('/[A-Za-z_$][A-Za-z0-9_$]*/', $value, $targets, \PREG_OFFSET_CAPTURE);
            foreach ($targets[0] as [$name, $offset]) {
                $references[] = new StimulusReference($controller, StimulusMemberKind::Target, $name, $uri, $this->range($text, $valueOffset + $offset, \strlen($name)));
            }
        }
        preg_match_all('/\bstimulus_controller\s*\(\s*([\'"])([^\'"]+)\1/', $text, $controllers, \PREG_OFFSET_CAPTURE);
        foreach ($controllers[2] as [$name, $offset]) {
            $references[] = new StimulusReference($name, null, null, $uri, $this->range($text, $offset, \strlen($name)));
        }
        foreach (['action' => StimulusMemberKind::Action, 'target' => StimulusMemberKind::Target] as $function => $kind) {
            preg_match_all('/\bstimulus_'.$function.'\s*\(\s*([\'"])([^\'"]+)\1\s*,\s*([\'"])([^\'"]+)\3/', $text, $calls, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($calls as $call) {
                $controller = $call[2][0];
                $member = $call[4][0];
                $references[] = new StimulusReference($controller, null, null, $uri, $this->range($text, $call[2][1], \strlen($controller)));
                $references[] = new StimulusReference($controller, $kind, $member, $uri, $this->range($text, $call[4][1], \strlen($member)));
            }
        }

        return $references;
    }

    /** @param list<StimulusReference> $references */
    private function appendControllerTokens(array &$references, string $uri, string $text, string $value, int $valueOffset): void
    {
        preg_match_all('/[A-Za-z0-9_@.\/-]+/', $value, $controllers, \PREG_OFFSET_CAPTURE);
        foreach ($controllers[0] as [$name, $offset]) {
            $references[] = new StimulusReference($name, null, null, $uri, $this->range($text, $valueOffset + $offset, \strlen($name)));
        }
    }

    private function controllerName(Project $project, string $uri): ?string
    {
        $path = rawurldecode((string) parse_url($uri, \PHP_URL_PATH));
        $root = rtrim(str_replace('\\', '/', $project->rootPath()), '/').'/assets/controllers/';
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $root)) {
            return null;
        }
        $relative = substr($path, \strlen($root));
        if (!preg_match('/^(.*?)(?:_|-)controller\.[jt]s$/', $relative, $match)) {
            return null;
        }

        return str_replace(['_', '/'], ['-', '--'], $match[1]);
    }

    private function range(string $text, int $offset, int $length): Range
    {
        return new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + $length));
    }
}
