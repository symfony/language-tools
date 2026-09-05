<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class PhpAutowireArgumentResolver
{
    private const AUTOWIRE = 'Symfony\\Component\\DependencyInjection\\Attribute\\Autowire';

    public function __construct(private readonly PhpParserInterface $parser)
    {
    }

    public function resolve(string $text, int $cursor): ?PhpAutowireArgument
    {
        foreach ($this->parser->parse($text)->attributesNamed(self::AUTOWIRE) as $attribute) {
            foreach ($attribute->arguments as $position => $argument) {
                $start = $argument->expressionStartOffset;
                $end = $argument->expressionEndOffset;
                if ($argument->unpacked || !\is_int($start) || !\is_int($end) || $cursor <= $start || $cursor > $end) {
                    continue;
                }

                $value = substr($text, $start, $cursor - $start);
                if (!\in_array($value[0], ['\'', '"'], true)) {
                    return null;
                }

                $value = substr($value, 1);
                if (preg_match('/[\'"\r\n]/', $value)) {
                    return null;
                }

                return new PhpAutowireArgument($argument->name, $position, $start + 1, $value);
            }
        }

        return null;
    }
}
