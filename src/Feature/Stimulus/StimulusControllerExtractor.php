<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\JavaScript\JavaScriptTokens;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathPolicy;
use Symfony\Lsp\Project\ProjectPathResolver;

final class StimulusControllerExtractor
{
    private const APPLICATION_IDENTIFIERS = ['application', 'this.application'];

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly ProjectPathResolver $pathResolver,
        private readonly StimulusControllerNameNormalizer $controllerNameNormalizer,
        private readonly StimulusControllerSourceAnalyzer $analyzer,
    ) {
    }

    /** @return list<StimulusControllerDeclaration> */
    public function extract(Project $project, string $uri, string $text, JavaScriptTokens $tokens): array
    {
        $name = $this->controllerName($project, $uri);
        if (null === $name) {
            return $this->registrations($project, $uri, $text, $tokens);
        }
        $source = $this->analyzer->analyze($text, $tokens);

        return [
            new StimulusControllerDeclaration($name, $uri, $source->range, $source->members, $source->lazy),
            ...$this->registrations($project, $uri, $text, $tokens),
        ];
    }

    /** @return list<StimulusControllerDeclaration> */
    private function registrations(Project $project, string $uri, string $text, JavaScriptTokens $tokens): array
    {
        if (!$this->isAssetFile($project, $uri)) {
            return [];
        }
        $applications = $this->applicationIdentifiers($tokens);
        $declarations = [];
        for ($index = 0, $count = $tokens->count(); $index < $count; ++$index) {
            if (!$tokens->isIdentifier($index, 'register')
                || !$tokens->isPunctuator($index + 1, '(')
                || !$tokens->isString($index + 2)
                || !$tokens->isPunctuator($index + 3, ',')
            ) {
                continue;
            }
            $name = $tokens->at($index + 2);
            if (null === $name || '' === $name->value || !\in_array($tokens->receiver($index), $applications, true)) {
                continue;
            }
            $declarations[] = new StimulusControllerDeclaration(
                $name->value,
                $uri,
                $this->converter->toRange($text, $name->offset, $name->length()),
                [],
                false,
            );
        }

        return $declarations;
    }

    /** @return list<string> */
    private function applicationIdentifiers(JavaScriptTokens $tokens): array
    {
        $identifiers = self::APPLICATION_IDENTIFIERS;
        for ($index = 0, $count = $tokens->count(); $index < $count; ++$index) {
            $target = $tokens->identifier($index);
            if (null === $target || $tokens->isPunctuator($index - 1, '.') || !$tokens->isPunctuator($index + 1, '=')) {
                continue;
            }
            $factory = $tokens->isIdentifier($index + 2, 'await') ? $index + 3 : $index + 2;
            if (($tokens->isIdentifier($factory, 'startStimulusApp') && $tokens->isPunctuator($factory + 1, '('))
                || ($tokens->isIdentifier($factory, 'Application') && $tokens->isPunctuator($factory + 1, '.') && $tokens->isIdentifier($factory + 2, 'start') && $tokens->isPunctuator($factory + 3, '('))
            ) {
                $identifiers[] = $target->value;
            }
        }

        return $identifiers;
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
