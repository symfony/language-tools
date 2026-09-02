<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpObjectCreation;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpStringLiteral;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;

final class PhpTranslationReferenceExtractor
{
    private const TRANSLATION_HELPER = 'Symfony\\Component\\Translation\\t';
    private const GLOBAL_PARAMETER_TRANSLATORS = [
        'Symfony\\Bundle\\FrameworkBundle\\Translation\\Translator',
        'Symfony\\Component\\Translation\\DataCollectorTranslator',
        'Symfony\\Component\\Translation\\LoggingTranslator',
        'Symfony\\Component\\Translation\\Translator',
    ];

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $parser,
        private readonly TranslationParameterAnalyzer $parameters,
    ) {
    }

    public function extract(string $uri, string $text): PhpTranslationFacts
    {
        $document = $this->parser->parse($text);
        $references = [];
        $globalParameters = [];
        $dynamicGlobalParameters = false;
        foreach ($document->methodCalls as $call) {
            if ('addGlobalParameter' === $call->method && $this->hasGlobalParameterReceiver($call, $document)) {
                $parameter = $call->namedOrPositionalArgument('id', 0);
                if (null === $parameter?->stringLiteral) {
                    $dynamicGlobalParameters = true;
                } else {
                    $globalParameters[] = $parameter->stringLiteral->value;
                }

                continue;
            }
            if ('trans' !== $call->method) {
                continue;
            }
            $key = ($call->argument('id') ?? $call->namedOrPositionalArgument('key', 0))?->stringLiteral;
            if (null === $key || null === $domain = $this->domain($call->namedOrPositionalArgument('domain', 2))) {
                continue;
            }
            $references[] = [
                'offset' => $key->startOffset,
                'reference' => $this->reference(
                    $key,
                    $domain,
                    $uri,
                    $text,
                    $this->parameters->php($call->namedOrPositionalArgument('parameters', 1)),
                ),
            ];
        }
        foreach ($document->objectCreations as $creation) {
            if (!$this->isTranslatableMessage($creation)) {
                continue;
            }
            $key = $creation->namedOrPositionalArgument('message', 0)?->stringLiteral;
            if (null === $key || null === $domain = $this->domain($creation->namedOrPositionalArgument('domain', 2))) {
                continue;
            }
            $references[] = [
                'offset' => $key->startOffset,
                'reference' => $this->reference(
                    $key,
                    $domain,
                    $uri,
                    $text,
                    $this->parameters->php($creation->namedOrPositionalArgument('parameters', 1)),
                ),
            ];
        }
        array_push($references, ...$this->helperReferences($uri, $text, $document));
        usort($references, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);
        $globalParameters = array_values(array_unique($globalParameters));
        sort($globalParameters);

        return new PhpTranslationFacts(
            array_column($references, 'reference'),
            $globalParameters,
            $dynamicGlobalParameters,
        );
    }

    private function hasGlobalParameterReceiver(PhpMethodCall $call, PhpDocument $document): bool
    {
        return array_any(
            $document->receiverVariables($call),
            static fn ($variable): bool => [] !== array_intersect(self::GLOBAL_PARAMETER_TRANSLATORS, $variable->types),
        );
    }

    private function domain(?PhpArgument $argument): ?string
    {
        return null === $argument ? 'messages' : $argument->stringLiteral?->value;
    }

    private function isTranslatableMessage(PhpObjectCreation $creation): bool
    {
        return 'Symfony\\Component\\Translation\\TranslatableMessage' === $creation->className;
    }

    /** @param list<string>|null $placeholders */
    private function reference(PhpStringLiteral $key, string $domain, string $uri, string $text, ?array $placeholders): TranslationReference
    {
        return new TranslationReference(
            $key->value,
            $domain,
            $uri,
            $this->converter->toRange($text, $key->startOffset, $key->endOffset - $key->startOffset),
            $placeholders,
        );
    }

    /** @return list<array{offset: int, reference: TranslationReference}> */
    private function helperReferences(string $uri, string $text, PhpDocument $document): array
    {
        $tokens = array_values(\PhpToken::tokenize($text));
        $helperNames = $this->importedHelperNames($tokens);
        if (0 === strcasecmp('Symfony\\Component\\Translation', $document->namespace())) {
            $helperNames['t'] = true;
        }

        $references = [];
        foreach ($tokens as $index => $token) {
            $fullyQualified = \T_NAME_FULLY_QUALIFIED === $token->id && 0 === strcasecmp(self::TRANSLATION_HELPER, ltrim($token->text, '\\'));
            if (!$fullyQualified && (\T_STRING !== $token->id || !isset($helperNames[strtolower($token->text)]))) {
                continue;
            }
            $previous = $this->previousSignificantToken($tokens, $index);
            if (null !== $previous && $previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON])) {
                continue;
            }
            $literal = $this->helperArgument($tokens, $index);
            if (null === $literal) {
                continue;
            }
            $quote = $literal->text[0];
            $raw = substr($literal->text, 1, -1);
            if (!$this->isSupportedHelperLiteral($quote, $raw)) {
                continue;
            }
            $offset = $literal->pos + 1;
            $references[] = [
                'offset' => $offset,
                'reference' => new TranslationReference(
                    PhpStringLiteralDecoder::decode($quote, $raw),
                    'messages',
                    $uri,
                    $this->converter->toRange($text, $offset, \strlen($raw)),
                ),
            ];
        }

        return $references;
    }

    /**
     * @param list<\PhpToken> $tokens
     *
     * @return array<string, true>
     */
    private function importedHelperNames(array $tokens): array
    {
        $names = [];
        foreach ($tokens as $index => $token) {
            if (\T_USE !== $token->id) {
                continue;
            }
            $functionIndex = $this->nextSignificantIndex($tokens, $index);
            if (null === $functionIndex || \T_FUNCTION !== $tokens[$functionIndex]->id) {
                continue;
            }
            $statement = '';
            for ($cursor = $functionIndex + 1; isset($tokens[$cursor]) && ';' !== $tokens[$cursor]->text; ++$cursor) {
                $statement .= $tokens[$cursor]->is([\T_COMMENT, \T_DOC_COMMENT]) ? ' ' : $tokens[$cursor]->text;
            }
            preg_match_all(
                '/(?:^|,)\s*\\\\?Symfony\\\\Component\\\\Translation\\\\t(?:\s+as\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*))?\s*(?=,|$)/i',
                trim($statement),
                $matches,
            );
            foreach ($matches[1] as $alias) {
                $names[strtolower('' === $alias ? 't' : $alias)] = true;
            }
            if (preg_match('/^\s*\\\\?Symfony\\\\Component\\\\Translation\\\\\{(?<imports>.*)\}\s*$/is', $statement, $group)) {
                preg_match_all(
                    '/(?:^|,)\s*t(?:\s+as\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*))?\s*(?=,|$)/i',
                    $group['imports'],
                    $matches,
                );
                foreach ($matches[1] as $alias) {
                    $names[strtolower('' === $alias ? 't' : $alias)] = true;
                }
            }
        }

        return $names;
    }

    /** @param list<\PhpToken> $tokens */
    private function helperArgument(array $tokens, int $callIndex): ?\PhpToken
    {
        $openIndex = $this->nextSignificantIndex($tokens, $callIndex);
        if (null === $openIndex || '(' !== $tokens[$openIndex]->text) {
            return null;
        }
        $argumentIndex = $this->nextSignificantIndex($tokens, $openIndex);
        if (null === $argumentIndex) {
            return null;
        }
        if (\T_STRING === $tokens[$argumentIndex]->id && 0 === strcasecmp('message', $tokens[$argumentIndex]->text)) {
            $colonIndex = $this->nextSignificantIndex($tokens, $argumentIndex);
            if (null === $colonIndex || ':' !== $tokens[$colonIndex]->text) {
                return null;
            }
            $argumentIndex = $this->nextSignificantIndex($tokens, $colonIndex);
        }
        if (null === $argumentIndex || \T_CONSTANT_ENCAPSED_STRING !== $tokens[$argumentIndex]->id) {
            return null;
        }
        $endIndex = $this->nextSignificantIndex($tokens, $argumentIndex);
        if (null === $endIndex || !\in_array($tokens[$endIndex]->text, [',', ')'], true)) {
            return null;
        }

        return $tokens[$argumentIndex];
    }

    private function isSupportedHelperLiteral(string $quote, string $raw): bool
    {
        if ('' === $raw) {
            return false;
        }

        return "'" === $quote
            ? 1 === preg_match('/^(?:\\\\.|[^\'\\\\])+$/s', $raw)
            : 1 === preg_match('/^(?:\\\\[\\\\"]|[^"\\\\$])+$/s', $raw);
    }

    /** @param list<\PhpToken> $tokens */
    private function previousSignificantToken(array $tokens, int $index): ?\PhpToken
    {
        while (--$index >= 0) {
            if (!$tokens[$index]->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                return $tokens[$index];
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private function nextSignificantIndex(array $tokens, int $index): ?int
    {
        while (isset($tokens[++$index])) {
            if (!$tokens[$index]->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                return $index;
            }
        }

        return null;
    }
}
