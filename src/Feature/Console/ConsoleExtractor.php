<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpAttributeTargetKind;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpMethodReceiverKind;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;
use Symfony\Lsp\Parser\Php\PhpTypeDeclaration;
use Symfony\Lsp\Parser\Php\PhpTypeKind;

final class ConsoleExtractor
{
    private const AS_COMMAND_ATTRIBUTE = 'Symfony\\Component\\Console\\Attribute\\AsCommand';
    private const COMMAND = 'Symfony\\Component\\Console\\Command\\Command';

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $parser,
        private readonly PhpCommentParser $phpComments,
        private readonly ConsoleDefinitionExtractor $definitionExtractor,
        private readonly ConsoleInvokableParameterExtractor $invokableParameterExtractor,
        private readonly ConsoleInputReceiverResolver $inputReceivers,
    ) {
    }

    public function extract(SourceDocument $document): ConsoleSourceFacts
    {
        if ('php' !== $document->languageId) {
            return new ConsoleSourceFacts($document->uri, [], []);
        }

        $masked = $this->phpComments->mask($document->text);
        $php = $this->parser->parse($document->text);
        $declarations = [];
        foreach ($php->typeDeclarations as $type) {
            if (!\in_array($type->kind, [PhpTypeKind::Class_, PhpTypeKind::Trait_], true)) {
                continue;
            }
            $declarations[] = $this->declaration($masked, $php, $type);
        }

        $references = [];
        foreach ($php->methodCalls as $call) {
            if (!\in_array($call->method, ['getArgument', 'getOption'], true) || !$this->inputReceivers->hasInputReceiver($masked, $php, $call)) {
                continue;
            }
            $name = $call->positionalArgument(0)?->stringLiteral;
            $className = $call->className;
            if (null === $name || null === $className) {
                continue;
            }
            $references[] = new ConsoleInputReference(
                'getArgument' === $call->method ? ConsoleInputKind::Argument : ConsoleInputKind::Option,
                $name->value,
                $document->uri,
                new Range($this->converter->toPosition($document->text, $name->startOffset), $this->converter->toPosition($document->text, $name->endOffset)),
                $className,
            );
        }

        return new ConsoleSourceFacts($document->uri, $declarations, $references);
    }

    public function completionContext(string $languageId, string $text, int $offset): ?ConsoleCompletionContext
    {
        if ('php' !== $languageId) {
            return null;
        }
        $masked = $this->phpComments->mask($text);
        $before = substr($masked, 0, $offset);
        if (!preg_match('/(?:\$([A-Za-z_][A-Za-z0-9_]*)|\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*))\s*->\s*(getArgument|getOption)\s*\(\s*([\'\"])(?<prefix>(?:\\\\.|(?!\4).)*)$/s', $before, $match, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        $php = $this->parser->parse($text);
        $methodOffset = $match[3][1];
        $property = \is_string($match[2][0] ?? null);
        $receiver = $property ? $match[2][0] : ($match[1][0] ?? null);
        $receiverKind = $property ? PhpMethodReceiverKind::ThisProperty : PhpMethodReceiverKind::Variable;
        $call = \is_string($receiver) ? array_find($php->methodCalls, static fn (PhpMethodCall $call): bool => $match[3][0] === $call->method && $receiver === $call->receiverContext->name && $receiverKind === $call->receiverContext->kind && $methodOffset >= $call->startOffset && $methodOffset < $call->endOffset) : null;
        if (null === $call || null === $call->className || !$this->inputReceivers->hasInputReceiver($masked, $php, $call)) {
            return null;
        }
        $rawPrefix = $match['prefix'][0];
        $prefixOffset = $match['prefix'][1];
        if (!\is_string($rawPrefix)) {
            return null;
        }
        $quote = $match[4][0];
        if (!\is_string($quote) || ('"' === $quote && str_contains($rawPrefix, '$'))) {
            return null;
        }
        $prefix = PhpStringLiteralDecoder::decode($quote, $rawPrefix);

        return new ConsoleCompletionContext(
            'getArgument' === $match[3][0] ? ConsoleInputKind::Argument : ConsoleInputKind::Option,
            $prefix,
            new Range($this->converter->toPosition($text, $prefixOffset), $this->converter->toPosition($text, $offset)),
            $call->className,
        );
    }

    private function declaration(string $text, PhpDocument $php, PhpTypeDeclaration $type): ConsoleCommandDeclaration
    {
        [$arguments, $options, $complete] = $this->definitionExtractor->extract($text, $php, $type);
        [$traits, $attributeArguments, $attributeOptions, $attributesComplete] = $this->invokableParameterExtractor->extract($text, $php, $type);
        $arguments = array_values(array_unique([...$arguments, ...$attributeArguments]));
        $options = array_values(array_unique([...$options, ...$attributeOptions]));
        sort($arguments);
        sort($options);

        return new ConsoleCommandDeclaration(
            $type->name,
            $type->parentClassName,
            $traits,
            $arguments,
            $options,
            array_any($php->attributesOn(PhpAttributeTargetKind::Type, $type->name), static fn ($attribute): bool => self::AS_COMMAND_ATTRIBUTE === $attribute->name)
                || 0 === strcasecmp(self::COMMAND, (string) $type->parentClassName),
            $complete && $attributesComplete,
        );
    }
}
