<?php

namespace Symfony\Lsp\Index;

final class PhpRuntimeStructureHasher
{
    private const EXECUTED_SOURCE_PATTERN = '/\b(?:extends|implements)\s+[^;{]*(?:AbstractExtension|AbstractType|Bundle|CompilerPassInterface|EnvVarProcessorInterface|EventSubscriberInterface|ExtensionInterface|FormTypeInterface|LoaderInterface|ServiceSubscriberInterface)\b/';

    public function hash(string $relativePath, string $text): ?string
    {
        if (!str_ends_with($relativePath, '.php')) {
            return null;
        }
        if ($this->isExecutedSource($relativePath, $text)) {
            return hash('sha256', $text);
        }

        $structure = '';
        $function = false;
        $bodyDepth = 0;
        foreach (token_get_all($text) as $token) {
            $tokenId = \is_array($token) ? $token[0] : null;
            $tokenText = \is_array($token) ? $token[1] : $token;
            if ($bodyDepth > 0) {
                if ('{' === $tokenText) {
                    ++$bodyDepth;
                } elseif ('}' === $tokenText) {
                    --$bodyDepth;
                }
                continue;
            }
            if (\in_array($tokenId, [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            if (\T_FUNCTION === $tokenId) {
                $function = true;
            }
            if ($function && '{' === $tokenText) {
                $structure .= '{}';
                $function = false;
                $bodyDepth = 1;
                continue;
            }
            if ($function && ';' === $tokenText) {
                $function = false;
            }
            $structure .= \T_WHITESPACE === $tokenId ? ' ' : $tokenText;
        }

        return hash('sha256', $structure);
    }

    private function isExecutedSource(string $relativePath, string $text): bool
    {
        foreach (['config/', 'src/DependencyInjection/', 'src/EventSubscriber/', 'src/Form/', 'src/Twig/', 'src/Validator/'] as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }

        $code = '';
        foreach (token_get_all($text) as $token) {
            if (!\is_array($token) || !\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                $code .= \is_array($token) ? $token[1] : $token;
            }
        }

        return 'src/Kernel.php' === $relativePath
            || 1 === preg_match(self::EXECUTED_SOURCE_PATTERN, $code)
            || 1 === preg_match('/#\[\s*\\\\?Attribute\b/', $code);
    }
}
