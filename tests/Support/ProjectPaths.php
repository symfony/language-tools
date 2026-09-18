<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Index\SourceFileEnumerator;
use Symfony\Lsp\Project\GitignoreMatcher;
use Symfony\Lsp\Project\GlobPatternCompiler;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;
use Symfony\Lsp\Project\ProjectPathPolicy;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\UriToPathConverter;

final class ProjectPaths
{
    public static function policy(?GitignoreMatcher $gitignore = null): ProjectPathPolicy
    {
        return new ProjectPathPolicy($gitignore ?? new GitignoreMatcher());
    }

    public static function resolver(?UriToPathConverter $uriToPathConverter = null): ProjectPathResolver
    {
        return new ProjectPathResolver($uriToPathConverter ?? new UriToPathConverter(), self::policy());
    }

    public static function enumerator(?ProjectFileScopeRegistry $fileScope = null, ?GitignoreMatcher $gitignore = null): SourceFileEnumerator
    {
        return new SourceFileEnumerator(self::policy($gitignore), $fileScope ?? new ProjectFileScopeRegistry(new GlobPatternCompiler()));
    }
}
