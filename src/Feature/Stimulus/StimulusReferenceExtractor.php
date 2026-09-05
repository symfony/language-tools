<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\Twig\TwigCallArgumentResolver;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Twig\TwigDocument;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Parser\Twig\TwigStringLiteral;

final class StimulusReferenceExtractor
{
    private const HELPER_MEMBER_KINDS = [
        'stimulus_controller' => null,
        'stimulus_action' => StimulusMemberKind::Action,
        'stimulus_target' => StimulusMemberKind::Target,
    ];

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly TwigCommentParser $commentParser,
        private readonly JavaScriptSourceAnalyzer $codeMasker,
        private readonly StimulusControllerNameNormalizer $controllerNameNormalizer,
        private readonly TwigDocumentParser $parser,
        private readonly TwigCallArgumentResolver $arguments,
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
        array_push($references, ...$this->helperReferences($uri, $text));

        return $references;
    }

    /** @return list<StimulusReference> */
    private function helperReferences(string $uri, string $text): array
    {
        $document = $this->parser->parse($text);
        $references = [];
        foreach ($document->nodesOfType('function_call') as $call) {
            $identifier = $document->directChild($call, 'function_identifier');
            $function = null === $identifier ? '' : $document->text($identifier);
            if (!\array_key_exists($function, self::HELPER_MEMBER_KINDS)) {
                continue;
            }
            $kind = self::HELPER_MEMBER_KINDS[$function];
            $arguments = $this->arguments->resolve($document, $call);
            $controller = $this->literal($document, $arguments->get(0));
            $member = null === $kind ? null : $this->literal($document, $arguments->get(1));
            if (null === $controller || (null !== $kind && null === $member)) {
                continue;
            }
            $name = $this->controllerNameNormalizer->normalize($controller->value);
            $references[] = new StimulusReference($name, null, null, $uri, $this->range($text, $controller));
            if (null !== $member) {
                $references[] = new StimulusReference($name, $kind, $member->value, $uri, $this->range($text, $member));
            }
        }

        return $references;
    }

    private function literal(TwigDocument $document, ?TreeSitterNode $argument): ?TwigStringLiteral
    {
        $literal = null === $argument ? null : $document->soleStringLiteral($argument);

        return null === $literal || '' === $literal->value ? null : $literal;
    }

    private function range(string $text, TwigStringLiteral $literal): Range
    {
        return $this->converter->toRange($text, $literal->startOffset, $literal->endOffset - $literal->startOffset);
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
