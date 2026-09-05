<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpAttribute;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpMethodDeclaration;
use Symfony\Lsp\Parser\Php\PhpTypeDeclaration;

final class ConsoleInvokableParameterExtractor
{
    private const ARGUMENT_ATTRIBUTE = 'Symfony\\Component\\Console\\Attribute\\Argument';
    private const OPTION_ATTRIBUTE = 'Symfony\\Component\\Console\\Attribute\\Option';

    /** @return array{list<string>, list<string>, list<string>, bool} */
    public function extract(PhpDocument $php, PhpTypeDeclaration $type): array
    {
        $traits = array_values(array_unique($type->traitNames));
        sort($traits);
        $invoke = array_find($php->methodDeclarations, static fn (PhpMethodDeclaration $method): bool => $type->name === $method->className && 0 === strcasecmp('__invoke', $method->name));
        if (null === $invoke) {
            return [$traits, [], [], true];
        }
        $arguments = [];
        $options = [];
        $complete = true;
        foreach ($invoke->parameters as $parameter) {
            foreach ($parameter->attributes as $attribute) {
                $kind = match ($attribute->name) {
                    self::ARGUMENT_ATTRIBUTE => ConsoleInputKind::Argument,
                    self::OPTION_ATTRIBUTE => ConsoleInputKind::Option,
                    default => null,
                };
                if (null === $kind) {
                    continue;
                }
                $name = $this->inputName($attribute, $parameter->name);
                if (null === $name) {
                    $complete = false;
                    continue;
                }
                if (ConsoleInputKind::Argument === $kind) {
                    $arguments[] = $name;
                } else {
                    $options[] = $name;
                }
            }
        }

        return [$traits, $arguments, $options, $complete];
    }

    private function inputName(PhpAttribute $attribute, string $parameter): ?string
    {
        if (array_any($attribute->arguments, static fn (PhpArgument $argument): bool => $argument->unpacked)) {
            return null;
        }
        $name = $attribute->namedOrPositionalArgument('name', 1);
        if (null === $name) {
            return $this->parameterName($parameter);
        }

        return $name->stringLiteral?->value;
    }

    /** Infers the input name from the parameter name the way Symfony's UnicodeString::kebab() does. */
    private function parameterName(string $parameter): string
    {
        $first = true;
        $camel = preg_replace_callback('/\b.(?!\p{Lu})/u', static function (array $match) use (&$first): string {
            $character = $first ? mb_strtolower($match[0]) : mb_convert_case($match[0], \MB_CASE_TITLE);
            $first = false;

            return $character;
        }, (string) preg_replace('/[^\pL0-9]++/u', ' ', $parameter));
        $snake = preg_replace(['/(\p{Lu}+)(\p{Lu}\p{Ll})/u', '/([\p{Ll}0-9])(\p{Lu})/u'], '$1_$2', str_replace(' ', '', (string) $camel));

        return str_replace('_', '-', mb_strtolower((string) $snake));
    }
}
