<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigStringDecoder;

final class StimulusReferenceExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigCommentParser $commentParser,
        private readonly JavaScriptSourceAnalyzer $codeMasker,
    ) {
    }

    /** @return list<StimulusReference> */
    public function extractJavaScript(string $uri, string $text): array
    {
        $code = $this->codeMasker->mask($text);
        $references = [];
        foreach ([
            '/\b(?:application|this\.application)\s*\.\s*register\s*\(\s*([\'"])([^\'"]+)\1/',
            '/\b(?:application|this\.application)\s*\.\s*getControllerForElementAndIdentifier\s*\([^,]+,\s*([\'"])([^\'"]+)\1/',
        ] as $pattern) {
            preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                if (' ' === $code[$match[0][1]]) {
                    continue;
                }
                [$name, $offset] = $match[2];
                $references[] = new StimulusReference($name, null, null, $uri, $this->converter->toRange($text, $offset, \strlen($name)));
            }
        }

        return $references;
    }

    /** @return list<StimulusReference> */
    public function extractTwig(string $uri, string $text): array
    {
        $source = $this->commentParser->mask($text);
        $references = [];
        preg_match_all('/\bdata-controller\s*=\s*([\'"])(.*?)\1/s', $source, $attributes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($attributes as $attribute) {
            $value = $attribute[2][0];
            if (str_contains($value, '{{') || str_contains($value, '{%')) {
                continue;
            }
            $this->appendControllerTokens($references, $uri, $text, $value, $attribute[2][1]);
        }
        preg_match_all('/\bdata-action\s*=\s*([\'"])(.*?)\1/s', $source, $attributes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($attributes as $attribute) {
            $value = $attribute[2][0];
            $valueOffset = $attribute[2][1];
            preg_match_all('/(?:[^\s]+->)?([A-Za-z0-9_@.\/-]+)#([A-Za-z_$][A-Za-z0-9_$]*)/', $value, $actions, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($actions as $action) {
                $controller = $action[1][0];
                $name = $action[2][0];
                $references[] = new StimulusReference($controller, null, null, $uri, $this->converter->toRange($text, $valueOffset + $action[1][1], \strlen($controller)));
                $references[] = new StimulusReference($controller, StimulusMemberKind::Action, $name, $uri, $this->converter->toRange($text, $valueOffset + $action[2][1], \strlen($name)));
            }
        }
        preg_match_all('/\bdata-([A-Za-z0-9_@.-]+)-target\s*=\s*([\'"])(.*?)\2/s', $source, $attributes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($attributes as $attribute) {
            $controller = $attribute[1][0];
            $value = $attribute[3][0];
            $valueOffset = $attribute[3][1];
            preg_match_all('/[A-Za-z_$][A-Za-z0-9_$]*/', $value, $targets, \PREG_OFFSET_CAPTURE);
            foreach ($targets[0] as [$name, $offset]) {
                $references[] = new StimulusReference($controller, StimulusMemberKind::Target, $name, $uri, $this->converter->toRange($text, $valueOffset + $offset, \strlen($name)));
            }
        }
        preg_match_all('/\bstimulus_controller\s*\(\s*([\'"])((?:\\\\.|[^\'"])+)\1/', $source, $controllers, \PREG_OFFSET_CAPTURE);
        foreach ($controllers[2] as [$rawName, $offset]) {
            $references[] = new StimulusReference(TwigStringDecoder::decode($rawName), null, null, $uri, $this->converter->toRange($text, $offset, \strlen($rawName)));
        }
        foreach (['action' => StimulusMemberKind::Action, 'target' => StimulusMemberKind::Target] as $function => $kind) {
            preg_match_all('/\bstimulus_'.$function.'\s*\(\s*([\'"])((?:\\\\.|[^\'"])+)\1\s*,\s*([\'"])((?:\\\\.|[^\'"])+)\3/', $source, $calls, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($calls as $call) {
                $rawController = $call[2][0];
                $rawMember = $call[4][0];
                $controller = TwigStringDecoder::decode($rawController);
                $member = TwigStringDecoder::decode($rawMember);
                $references[] = new StimulusReference($controller, null, null, $uri, $this->converter->toRange($text, $call[2][1], \strlen($rawController)));
                $references[] = new StimulusReference($controller, $kind, $member, $uri, $this->converter->toRange($text, $call[4][1], \strlen($rawMember)));
            }
        }

        return $references;
    }

    /** @param list<StimulusReference> $references */
    private function appendControllerTokens(array &$references, string $uri, string $text, string $value, int $valueOffset): void
    {
        preg_match_all('/[A-Za-z0-9_@.\/-]+/', $value, $controllers, \PREG_OFFSET_CAPTURE);
        foreach ($controllers[0] as [$name, $offset]) {
            $references[] = new StimulusReference($name, null, null, $uri, $this->converter->toRange($text, $valueOffset + $offset, \strlen($name)));
        }
    }
}
