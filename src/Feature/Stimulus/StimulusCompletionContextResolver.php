<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;

final class StimulusCompletionContextResolver
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigCommentParser $commentParser,
        private readonly StimulusControllerNameNormalizer $controllerNameNormalizer,
    ) {
    }

    public function resolve(string $languageId, string $text, int $offset): ?StimulusCompletionContext
    {
        if ('twig' !== $languageId) {
            return null;
        }
        $before = substr($this->commentParser->mask($text), 0, $offset);
        if (preg_match('/\bstimulus_(?:action|target)\s*\(\s*([\'"])([^\'"]+)\1\s*,\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            return new StimulusCompletionContext(
                str_contains($match[0], 'stimulus_action') ? StimulusMemberKind::Action : StimulusMemberKind::Target,
                $this->controllerNameNormalizer->normalize($match[2]),
                $match[4],
                $this->converter->toRange($text, $offset - \strlen($match[4]), \strlen($match[4])),
            );
        }
        if (preg_match('/\bstimulus_(?:controller|action|target)\s*\(\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            return new StimulusCompletionContext(null, null, $this->controllerNameNormalizer->normalize($match[2]), $this->converter->toRange($text, $offset - \strlen($match[2]), \strlen($match[2])));
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

                return new StimulusCompletionContext(StimulusMemberKind::Action, $controller, $prefix, $this->converter->toRange($text, $offset - \strlen($prefix), \strlen($prefix)));
            }

            return new StimulusCompletionContext(null, null, $descriptor, $this->converter->toRange($text, $offset - \strlen($descriptor), \strlen($descriptor)));
        }
        if (preg_match('/\bdata-controller\s*=\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            $prefix = preg_replace('/^.*\s/s', '', $match[2]);
            if (\is_string($prefix)) {
                return new StimulusCompletionContext(null, null, $prefix, $this->converter->toRange($text, $offset - \strlen($prefix), \strlen($prefix)));
            }
        }
        if (preg_match('/\bdata-([A-Za-z0-9_@.-]+)-target\s*=\s*([\'"])([^\'"]*)$/s', $before, $match)) {
            $prefix = preg_replace('/^.*\s/s', '', $match[3]);
            if (\is_string($prefix)) {
                return new StimulusCompletionContext(StimulusMemberKind::Target, $match[1], $prefix, $this->converter->toRange($text, $offset - \strlen($prefix), \strlen($prefix)));
            }
        }

        return null;
    }
}
