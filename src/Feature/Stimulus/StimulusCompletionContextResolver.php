<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Twig\TwigCallSyntax;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDirectiveLocator;

final class StimulusCompletionContextResolver
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigCommentParser $commentParser,
        private readonly StimulusControllerNameNormalizer $controllerNameNormalizer,
        private readonly TwigDirectiveLocator $directives,
    ) {
    }

    public function resolve(string $languageId, string $text, int $offset): ?StimulusCompletionContext
    {
        if ('twig' !== $languageId) {
            return null;
        }
        $masked = $this->commentParser->mask($text);
        $before = substr($masked, 0, $offset);
        if (!$this->directives->insideDirective($masked, $offset)) {
            return $this->attributeContext($before, $text, $offset);
        }
        if (preg_match('/\bstimulus_(action|target)\s*\(\s*(?:controllerName\s*[:=]\s*)?([\'"])([^\'"]+)\2\s*,\s*(?:(?:actionName|targetNames)\s*[:=]\s*)?([\'"])([^\'"]*)$/s', $before, $match, \PREG_OFFSET_CAPTURE)
            && !TwigCallSyntax::isMethodCall($before, $match[1][1] - \strlen('stimulus_'))
        ) {
            $kind = 'action' === $match[1][0] ? StimulusMemberKind::Action : StimulusMemberKind::Target;
            $prefix = StimulusMemberKind::Target === $kind ? (string) preg_replace('/^.*\s/s', '', $match[5][0]) : $match[5][0];

            return new StimulusCompletionContext(
                $kind,
                $this->controllerNameNormalizer->normalize($match[3][0]),
                $prefix,
                $this->converter->toRange($text, $offset - \strlen($prefix), \strlen($prefix)),
            );
        }
        if (preg_match('/\b(stimulus_(?:controller|action|target))\s*\(\s*(?:controllerName\s*[:=]\s*)?([\'"])([^\'"]*)$/s', $before, $match, \PREG_OFFSET_CAPTURE)
            && !TwigCallSyntax::isMethodCall($before, $match[1][1])
        ) {
            return new StimulusCompletionContext(null, null, $this->controllerNameNormalizer->normalize($match[3][0]), $this->converter->toRange($text, $offset - \strlen($match[3][0]), \strlen($match[3][0])));
        }

        return null;
    }

    /** Completion inside a `data-*` attribute value, which a template renders as markup. */
    private function attributeContext(string $before, string $text, int $offset): ?StimulusCompletionContext
    {
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
