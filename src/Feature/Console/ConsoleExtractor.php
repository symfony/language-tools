<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpAttributeTargetKind;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodCall;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Php\PhpReceiverMatch;
use Symfony\Lsp\Parser\Php\PhpTypeDeclaration;
use Symfony\Lsp\Parser\Php\PhpTypeKind;

final class ConsoleExtractor
{
    private const AS_COMMAND_ATTRIBUTE = 'Symfony\\Component\\Console\\Attribute\\AsCommand';
    private const COMMAND = 'Symfony\\Component\\Console\\Command\\Command';
    private const INPUT_INTERFACE = 'Symfony\\Component\\Console\\Input\\InputInterface';

    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $parser,
        private readonly PhpCommentParser $phpComments,
        private readonly ConsoleDefinitionExtractor $definitionExtractor,
        private readonly ConsoleInvokableParameterExtractor $invokableParameterExtractor,
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
            if (!\in_array($call->method, ['getArgument', 'getOption'], true) || PhpReceiverMatch::Matches !== $php->matchReceiver($call, self::INPUT_INTERFACE)) {
                continue;
            }
            $name = $call->namedOrPositionalArgument('name', 0)?->stringLiteral;
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
        $php = $this->parser->parse($text);
        $cursor = $php->argumentCursorAt($offset);
        $call = $cursor?->call;
        if (null === $cursor || !$call instanceof PhpMethodCall || !$cursor->isArgumentLiteral() || !$cursor->isNamedOrPositional('name', 0)) {
            return null;
        }
        $kind = match ($call->method) {
            'getArgument' => ConsoleInputKind::Argument,
            'getOption' => ConsoleInputKind::Option,
            default => null,
        };
        if (null === $kind || null === $call->className || PhpReceiverMatch::Matches !== $php->matchReceiver($call, self::INPUT_INTERFACE)) {
            return null;
        }

        return new ConsoleCompletionContext(
            $kind,
            $cursor->prefix,
            new Range($this->converter->toPosition($text, $cursor->prefixStartOffset), $this->converter->toPosition($text, $offset)),
            $call->className,
        );
    }

    private function declaration(string $text, PhpDocument $php, PhpTypeDeclaration $type): ConsoleCommandDeclaration
    {
        [$arguments, $options, $complete] = $this->definitionExtractor->extract($text, $php, $type);
        [$traits, $attributeArguments, $attributeOptions, $attributesComplete] = $this->invokableParameterExtractor->extract($php, $type);
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
