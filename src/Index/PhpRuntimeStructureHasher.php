<?php

namespace Symfony\Lsp\Index;

final class PhpRuntimeStructureHasher
{
    private const EXECUTED_SOURCE_PATTERN = '/\b(?:extends|implements)\s+[^;{]*(?:AbstractExtension|AbstractType|Bundle|CompilerPassInterface|EnvVarProcessorInterface|EventSubscriberInterface|ExtensionInterface|FormTypeInterface|Kernel|LoaderInterface|ServiceSubscriberInterface)\b/';

    public function analyze(string $relativePath, string $text): PhpRuntimeStructureAnalysis
    {
        if (!str_ends_with($relativePath, '.php')) {
            return new PhpRuntimeStructureAnalysis(null, false);
        }
        if ($this->pathRequiresFullTracking($relativePath)) {
            return new PhpRuntimeStructureAnalysis(hash('sha256', $text), true);
        }

        $code = '';
        $structure = '';
        $function = false;
        $bodyDepth = 0;
        foreach (token_get_all($text) as $token) {
            $tokenId = \is_array($token) ? $token[0] : null;
            $tokenText = \is_array($token) ? $token[1] : $token;
            if (!\in_array($tokenId, [\T_COMMENT, \T_DOC_COMMENT], true)) {
                $code .= $tokenText;
            }
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

        $requiresFullTracking = 1 === preg_match(self::EXECUTED_SOURCE_PATTERN, $code)
            || 1 === preg_match('/#\[\s*\\\\?Attribute\b/', $code);

        return new PhpRuntimeStructureAnalysis(
            hash('sha256', $requiresFullTracking ? $text : $structure),
            $requiresFullTracking,
        );
    }

    private function pathRequiresFullTracking(string $relativePath): bool
    {
        if ('src/Kernel.php' === $relativePath) {
            return true;
        }

        foreach (['config/', 'src/DependencyInjection/', 'src/EventSubscriber/', 'src/Form/', 'src/Twig/', 'src/Validator/'] as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
