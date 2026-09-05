<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ProbeFinder
{
    public const DEFAULT_ROOTS = ['src', 'templates', 'config'];

    private const EXCLUDED_DIRECTORIES = ['.git', 'node_modules', 'var', 'vendor'];

    private const DEFINITIONS = [
        ['category' => 'route.php', 'files' => '{\.php$}', 'pattern' => '{(?:->(?:generateUrl|redirectToRoute)|(?<![A-Za-z0-9_])(?i:router|urlgenerator|url_generator)->generate)\s*\(\s*[\'\"]([^\'\"]+)}'],
        ['category' => 'route.twig', 'files' => '{\.twig$}', 'pattern' => '{(?<![.\w|])(?:path|url)\(\s*[\'\"]([^\'\"]+)}'],
        ['category' => 'template.php', 'files' => '{\.php$}', 'pattern' => '{(?:render|renderView)\(\s*[\'\"]([^\'\"]+\.twig)}'],
        ['category' => 'template.twig', 'files' => '{\.twig$}', 'pattern' => '{\b(?:extends|include|embed|import|from|use)\s+[\'\"]([^\'\"]+\.twig)}'],
        ['category' => 'twig.constant', 'files' => '{\.twig$}', 'pattern' => '{\bconstant\(\s*[\'\"]App\\\\[A-Za-z0-9_\\\\]+::([A-Za-z_][A-Za-z0-9_]*)}'],
        ['category' => 'twig.enum', 'files' => '{\.twig$}', 'pattern' => '{\benum\(\s*[\'\"]App\\\\[A-Za-z0-9_\\\\]+[\'\"]\s*\)\s*\.\s*([A-Za-z_][A-Za-z0-9_]*)}'],
        ['category' => 'component.twig', 'files' => '{\.twig$}', 'pattern' => '{<twig:([A-Za-z_][A-Za-z0-9_:.-]*)\b}'],
        ['category' => 'stimulus.controller.twig', 'files' => '{\.twig$}', 'pattern' => '{\bdata-controller\s*=\s*[\'"][^\'"]*?([A-Za-z0-9_@./-]+)}'],
        ['category' => 'stimulus.action.twig', 'files' => '{\.twig$}', 'pattern' => '{\bdata-action\s*=\s*[\'"][^\'"]*?(?:[^\s\'"]+->)?[A-Za-z0-9_@./-]+#([A-Za-z_$][A-Za-z0-9_$]*)}'],
        ['category' => 'live.action.twig', 'files' => '{\.twig$}', 'pattern' => '{\bdata-live-action-param\s*=\s*[\'"](?:[^\'"]*\|)?([A-Za-z_][A-Za-z0-9_]*)}'],
        ['category' => 'live.event.php', 'files' => '{\.php$}', 'pattern' => '{\bemit\s*\(\s*[\'"]([^\'"]+)}'],
        ['category' => 'asset.twig', 'files' => '{\.twig$}', 'pattern' => '{\basset\s*\(\s*[\'\"]([A-Za-z0-9_@.-][A-Za-z0-9_@./-]*)[\'\"]\s*\)}'],
        ['category' => 'importmap.twig', 'files' => '{\.twig$}', 'pattern' => '{(?<![.\w])importmap\s*\(\s*(?:\[\s*)?[\'\"]([A-Za-z0-9_@./-]+)}'],
        ['category' => 'security.firewall.twig', 'files' => '{\.twig$}', 'pattern' => '{(?<![.\w])logout_(?:path|url)\s*\(\s*[\'\"]([A-Za-z0-9_.-]+)}'],
        ['category' => 'doctrine.field.repository.php', 'files' => '{\.php$}', 'pattern' => '{\b(?:findBy|findOneBy|count)\s*\(\s*\[\s*[\'\"]([A-Za-z_][A-Za-z0-9_]*)[\'\"]\s*=>}'],
        ['category' => 'doctrine.field.form.php', 'files' => '{\.php$}', 'pattern' => '{EntityType::class\s*,\s*\[[^;]+?[\'\"](?:choice_label|choice_value|group_by)[\'\"]\s*=>\s*[\'\"]([A-Za-z_][A-Za-z0-9_]*)}s'],
        ['category' => 'form.option.php', 'files' => '{\.php$}', 'pattern' => '{createForm\s*\([^,);]+,\s*[^,);]+,\s*\[[^;]+?[\'\"]([A-Za-z_][A-Za-z0-9_]*)[\'\"]\s*=>}s'],
        ['category' => 'form.property.php', 'files' => '{\.php$}', 'requiredText' => 'data_class', 'pattern' => '{(?=.*[\'\"]data_class[\'\"]\s*=>\s*[\\\\A-Za-z_][A-Za-z0-9_\\\\]*::class).*?^[ \t]*(?:\$builder\s*)?->\s*add\s*\(\s*[\'\"]([A-Za-z_][A-Za-z0-9_]*)[\'\"](?!(?:(?!->\s*add\s*\(|;).)*[\'\"]mapped[\'\"]\s*=>\s*false)}sm'],
        ['category' => 'constraint.option.php', 'files' => '{\.php$}', 'pattern' => '{Assert\\\\[A-Za-z_][A-Za-z0-9_]*\s*\([^\)]*?\b([A-Za-z_][A-Za-z0-9_]*)\s*:(?!:)}s'],
        ['category' => 'console.argument.php', 'files' => '{\.php$}', 'pattern' => '{\$input\s*->\s*getArgument\s*\(\s*[\'\"]([^\'\"]+)}'],
        ['category' => 'console.option.php', 'files' => '{\.php$}', 'pattern' => '{\$input\s*->\s*getOption\s*\(\s*[\'\"]([^\'\"]+)}'],
        ['category' => 'translation.php', 'files' => '{\.php$}', 'pattern' => '{(?:->trans|\bt)\(\s*[\'\"]([^\'\"]+)}'],
        ['category' => 'translation.twig', 'files' => '{\.twig$}', 'pattern' => '{[\'\"]([^\'\"]+)[\'\"]\s*\|\s*trans\b}'],
        ['category' => 'import.yaml', 'files' => '{\.ya?ml$}', 'pattern' => '{^[ \t]*(?:-[ \t]*)?(?:\{[ \t]*)?resource[ \t]*:[ \t]*[\'\"]?([A-Za-z0-9_.][^\'\"\s#,\}]*)}m'],
        ['category' => 'service.yaml', 'files' => '{\.ya?ml$}', 'pattern' => '{[\'\"]@([A-Za-z_][A-Za-z0-9_.\\\\]*)[\'\"]}'],
        ['category' => 'parameter.yaml', 'files' => '{\.ya?ml$}', 'pattern' => '{%([A-Za-z_][A-Za-z0-9_.]*)%}'],
        ['category' => 'environment', 'files' => '{\.(?:php|ya?ml)$}', 'pattern' => '{%env\([^)]*?([A-Z][A-Z0-9_]+)\)%}'],
    ];

    /**
     * @param list<string> $roots project-relative directories, scanned in order
     */
    public function __construct(
        private array $roots = self::DEFAULT_ROOTS,
        private int $probesPerCategory = 1,
    ) {
    }

    /**
     * @return list<Probe>
     */
    public function find(string $projectRoot): array
    {
        $files = $this->collectFiles($projectRoot);
        $probes = [];
        foreach (self::DEFINITIONS as $definition) {
            $found = 0;
            foreach ($files as $path => $contents) {
                if ($found >= $this->probesPerCategory) {
                    break;
                }
                if (1 !== preg_match($definition['files'], $path)) {
                    continue;
                }
                if (isset($definition['requiredText']) && !str_contains($contents, $definition['requiredText'])) {
                    continue;
                }
                $probe = $this->match($definition['category'], $definition['pattern'], $path, $contents);
                if (null !== $probe) {
                    $probes[] = $probe;
                    ++$found;
                }
            }
        }
        foreach (['Function', 'Filter'] as $kind) {
            array_push($probes, ...$this->findTwigCallables($files, $kind));
        }

        return $probes;
    }

    /**
     * @param array<string, string> $files
     *
     * @return list<Probe>
     */
    private function findTwigCallables(array $files, string $kind): array
    {
        $names = [];
        $declarations = [];
        $category = 'twig.'.strtolower($kind);
        foreach ($files as $path => $contents) {
            if (1 !== preg_match('{\.php$}', $path)) {
                continue;
            }
            preg_match_all('/\bnew\s+(?:\\\\?Twig\\\\)?Twig'.preg_quote($kind, '/').'\s*\(\s*([\'\"])([A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*)\1/', $contents, $matches, \PREG_OFFSET_CAPTURE);
            foreach ($matches[2] as [$name, $offset]) {
                $names[$name] = true;
                $declarations[$name] ??= $this->probe($category.'.php', $path, $contents, $name, $offset);
            }
            preg_match_all('/#\[\s*(?:\\\\?Twig\\\\Attribute\\\\)?AsTwig'.preg_quote($kind, '/').'\s*\(\s*(?:name\s*:\s*)?([\'"])([A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*)\1/', $contents, $matches, \PREG_OFFSET_CAPTURE);
            foreach ($matches[2] as [$name, $offset]) {
                $names[$name] = true;
                $declarations[$name] ??= $this->probe($category.'.php', $path, $contents, $name, $offset);
            }
        }
        if ([] === $names) {
            return [];
        }
        $alternatives = implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), array_keys($names)));
        $pattern = 'Function' === $kind
            ? '/\b('.$alternatives.')\s*\(/'
            : '/\|\s*('.$alternatives.')\b/';
        $probes = [];
        $declarationPaths = [];
        $found = 0;
        foreach ($files as $path => $contents) {
            if ($found >= $this->probesPerCategory) {
                break;
            }
            if (1 !== preg_match('{\.twig$}', $path)) {
                continue;
            }
            $probe = $this->match($category, $pattern, $path, $contents);
            if (null === $probe) {
                continue;
            }
            $probes[] = $probe;
            ++$found;
            $declaration = $declarations[$probe->value] ?? null;
            if (null !== $declaration && !isset($declarationPaths[$declaration->path])) {
                $probes[] = $declaration;
                $declarationPaths[$declaration->path] = true;
            }
        }

        return $probes;
    }

    private function match(string $category, string $pattern, string $path, string $contents): ?Probe
    {
        if (false === preg_match_all($pattern, $contents, $matches, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        foreach ($matches[1] as [$value, $offset]) {
            if ('' === $value || $this->isCommented($path, $contents, $offset)) {
                continue;
            }

            return $this->probe($category, $path, $contents, $value, $offset);
        }

        return null;
    }

    private function isCommented(string $path, string $contents, int $offset): bool
    {
        $lineStart = strrpos(substr($contents, 0, $offset), "\n");
        $lineStart = false === $lineStart ? 0 : $lineStart + 1;
        $prefix = substr($contents, $lineStart, $offset - $lineStart);
        if (1 === preg_match('{\.php$}', $path)) {
            return 1 === preg_match('{//|/\*|(?:^|\s)#(?!\[)}', $prefix);
        }
        if (1 === preg_match('{\.twig$}', $path)) {
            return str_contains($prefix, '{#') && false === strpos($prefix, '#}', (int) strrpos($prefix, '{#'));
        }

        return 1 === preg_match('{(?:^|\s)#}', $prefix);
    }

    private function probe(string $category, string $path, string $contents, string $value, int $offset): Probe
    {
        [$line, $character] = $this->position($contents, $offset + intdiv(\strlen($value), 2));

        return new Probe($category, $path, $contents, $value, $line, $character);
    }

    /**
     * @return array<string, string> path => contents, deterministically ordered
     */
    private function collectFiles(string $projectRoot): array
    {
        $files = [];
        foreach ($this->roots as $root) {
            $directory = $projectRoot.'/'.$root;
            if (!is_dir($directory)) {
                continue;
            }
            $paths = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => !$file->isDir() || !\in_array($file->getFilename(), self::EXCLUDED_DIRECTORIES, true),
            ));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $paths[] = str_replace('\\', '/', $file->getPathname());
                }
            }
            sort($paths, \SORT_STRING);
            foreach ($paths as $path) {
                if (isset($files[$path])) {
                    continue;
                }
                $contents = @file_get_contents($path);
                if (false !== $contents) {
                    $files[$path] = $contents;
                }
            }
        }

        return $files;
    }

    /**
     * @return array{int, int}
     */
    private function position(string $contents, int $offset): array
    {
        $before = substr($contents, 0, $offset);
        $line = substr_count($before, "\n");
        $lineStart = strrpos($before, "\n");
        $lineText = false === $lineStart ? $before : substr($before, $lineStart + 1);

        return [$line, intdiv(\strlen(mb_convert_encoding($lineText, 'UTF-16LE', 'UTF-8')), 2)];
    }
}
