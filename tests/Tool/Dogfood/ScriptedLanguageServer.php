<?php

namespace Symfony\Lsp\Tests\Tool\Dogfood;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * A language server process that answers from a declarative script and records everything it receives.
 */
final class ScriptedLanguageServer
{
    public readonly string $path;

    private readonly string $transcriptPath;

    /**
     * @param array{
     *     rootUri?: string,
     *     noise?: string,
     *     publishVersion?: bool|int,
     *     diagnostics?: list<array{contains: string, items: list<array{code: string, find: string, severity?: int, message?: string}>}>,
     *     responses?: list<array<string, mixed>>,
     * } $script
     */
    public function __construct(string $directory, array $script)
    {
        $filesystem = new Filesystem();
        $this->path = Path::join($directory, 'scripted-language-server');
        $this->transcriptPath = Path::join($directory, 'transcript.jsonl');
        $scriptPath = Path::join($directory, 'script.json');
        $script['transcript'] = $this->transcriptPath;
        $filesystem->dumpFile($scriptPath, json_encode($script, \JSON_THROW_ON_ERROR));
        $filesystem->dumpFile($this->path, \sprintf(
            "#!/usr/bin/env php\n<?php\n\nrequire %s;\n\$scriptPath = %s;\n%s",
            var_export(Path::join(\dirname(__DIR__, 3), 'vendor/autoload.php'), true),
            var_export($scriptPath, true),
            self::SOURCE,
        ));
        $filesystem->chmod($this->path, 0755);
    }

    /**
     * @return list<array<string, mixed>> the messages the server received, in order
     */
    public function transcript(): array
    {
        if (!is_file($this->transcriptPath)) {
            return [];
        }
        $messages = [];
        foreach (explode("\n", trim((string) file_get_contents($this->transcriptPath))) as $line) {
            if ('' !== $line) {
                /** @var array<string, mixed> $message */
                $message = (array) json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
                $messages[] = $message;
            }
        }

        return $messages;
    }

    public function started(): bool
    {
        return is_file($this->transcriptPath);
    }

    /**
     * @return list<string> the methods of the requests and notifications the server received
     */
    public function methods(): array
    {
        $methods = [];
        foreach ($this->transcript() as $message) {
            if (\is_string($message['method'] ?? null)) {
                $methods[] = $message['method'];
            }
        }

        return $methods;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function messages(string $method): array
    {
        return array_values(array_filter($this->transcript(), static fn (array $message): bool => $method === ($message['method'] ?? null)));
    }

    /**
     * @return list<string> the language identifiers the opened documents were announced with
     */
    public function openedLanguageIds(): array
    {
        $languageIds = [];
        foreach ($this->messages('textDocument/didOpen') as $message) {
            $textDocument = $this->map($message, 'params', 'textDocument');
            if (\is_string($textDocument['languageId'] ?? null)) {
                $languageIds[] = $textDocument['languageId'];
            }
        }

        return $languageIds;
    }

    /**
     * @return list<string> the full texts the documents were changed to, in order
     */
    public function changedTexts(): array
    {
        $texts = [];
        foreach ($this->messages('textDocument/didChange') as $message) {
            $changes = $this->map($message, 'params')['contentChanges'] ?? null;
            $text = \is_array($changes) && \is_array($changes[0] ?? null) ? ($changes[0]['text'] ?? null) : null;
            if (\is_string($text)) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * @return list<array{range: array<array-key, mixed>, diagnostics: list<array<array-key, mixed>>}>
     */
    public function codeActionContexts(): array
    {
        $contexts = [];
        foreach ($this->messages('textDocument/codeAction') as $message) {
            $parameters = $this->map($message, 'params');
            $range = $parameters['range'] ?? null;
            $diagnostics = $this->map($message, 'params', 'context')['diagnostics'] ?? null;
            $contexts[] = [
                'range' => \is_array($range) ? $range : [],
                'diagnostics' => array_values(array_filter(\is_array($diagnostics) ? $diagnostics : [], \is_array(...))),
            ];
        }

        return $contexts;
    }

    /**
     * @param array<array-key, mixed> $message
     *
     * @return array<array-key, mixed>
     */
    private function map(array $message, string ...$keys): array
    {
        foreach ($keys as $key) {
            $value = $message[$key] ?? null;
            $message = \is_array($value) ? $value : [];
        }

        return $message;
    }

    private const SOURCE = <<<'PHP'

        use Symfony\Lsp\Tools\ContentLengthMessageCodec;

        ini_set('display_errors', 'stderr');

        $script = json_decode((string) file_get_contents($scriptPath), true, flags: JSON_THROW_ON_ERROR);
        $codec = new ContentLengthMessageCodec();
        $documents = [];

        if (isset($script['noise'])) {
            fwrite(STDERR, $script['noise']);
            fflush(STDERR);
        }

        $position = static function (string $text, int $offset): array {
            $line = substr_count($text, "\n", 0, $offset);
            $lineStart = 0 === $line ? 0 : (int) strrpos(substr($text, 0, $offset), "\n") + 1;
            $prefix = substr($text, $lineStart, $offset - $lineStart);

            return ['line' => $line, 'character' => intdiv(strlen(mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')), 2)];
        };
        $range = static function (string $text, string $needle) use ($position): ?array {
            $offset = strpos($text, $needle);

            return false === $offset ? null : ['start' => $position($text, $offset), 'end' => $position($text, $offset + strlen($needle))];
        };
        $diagnostics = static function (string $text) use ($script, $range): array {
            foreach ($script['diagnostics'] ?? [] as $rule) {
                if (!str_contains($text, $rule['contains'])) {
                    continue;
                }
                $items = [];
                foreach ($rule['items'] as $item) {
                    $itemRange = $item['range'] ?? $range($text, $item['find']);
                    if (null !== $itemRange) {
                        $items[] = [
                            'code' => $item['code'],
                            'severity' => $item['severity'] ?? 1,
                            'message' => $item['message'] ?? 'Scripted diagnostic',
                            'range' => $itemRange,
                            'source' => 'symfony',
                        ];
                    }
                }

                return $items;
            }

            return [];
        };
        $send = static function (array $message) use ($codec): void {
            fwrite(STDOUT, $codec->encode($message));
            fflush(STDOUT);
        };
        $publish = static function (string $uri) use (&$documents, $script, $diagnostics, $send): void {
            $publishVersion = $script['publishVersion'] ?? true;
            if (false === $publishVersion) {
                return;
            }
            $send(['jsonrpc' => '2.0', 'method' => 'textDocument/publishDiagnostics', 'params' => [
                'uri' => $uri,
                'version' => is_int($publishVersion) ? $publishVersion : $documents[$uri]['version'],
                'diagnostics' => $diagnostics($documents[$uri]['text']),
            ]]);
        };
        $substitute = static function (mixed $value, array $replacements) use (&$substitute): mixed {
            if (is_string($value)) {
                return strtr($value, $replacements);
            }
            if (!is_array($value)) {
                return $value;
            }
            $substituted = [];
            foreach ($value as $key => $item) {
                $substituted[is_string($key) ? strtr($key, $replacements) : $key] = $substitute($item, $replacements);
            }

            return $substituted;
        };
        $actions = static function (array $declared, string $uri, string $text, int $version) use ($range): array {
            $built = [];
            foreach ($declared as $action) {
                if (isset($action['replace'])) {
                    $action['edit'] = ['documentChanges' => [[
                        'textDocument' => ['uri' => $uri, 'version' => $version],
                        'edits' => [['range' => $range($text, $action['replace']['find']), 'newText' => $action['replace']['newText']]],
                    ]]];
                    unset($action['replace']);
                }
                $built[] = $action + ['kind' => 'quickfix'];
            }

            return $built;
        };

        while (true) {
            try {
                $message = $codec->read(STDIN);
            } catch (Throwable) {
                break;
            }
            file_put_contents($script['transcript'], json_encode($message, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
            $method = $message['method'] ?? null;
            $uri = $message['params']['textDocument']['uri'] ?? null;
            if ('textDocument/didOpen' === $method) {
                $documents[$uri] = ['text' => $message['params']['textDocument']['text'], 'version' => $message['params']['textDocument']['version']];
                $publish($uri);
            } elseif ('textDocument/didChange' === $method) {
                $documents[$uri] = ['text' => $message['params']['contentChanges'][0]['text'], 'version' => $message['params']['textDocument']['version']];
                $publish($uri);
            } elseif ('textDocument/didClose' === $method) {
                unset($documents[$uri]);
            }
            if (isset($message['id'])) {
                $text = null === $uri ? '' : ($documents[$uri]['text'] ?? '');
                $matched = null;
                foreach ($script['responses'] ?? [] as $rule) {
                    if ($rule['method'] !== $method) {
                        continue;
                    }
                    if (isset($rule['contains']) && !str_contains($text, $rule['contains'])) {
                        continue;
                    }
                    if (isset($rule['requiresDiagnostic'])) {
                        $codes = array_column($message['params']['context']['diagnostics'] ?? [], 'code');
                        if (!in_array($rule['requiresDiagnostic'], $codes, true)) {
                            continue;
                        }
                    }
                    $matched = $rule;
                    break;
                }
                if (isset($matched['exit'])) {
                    exit(3);
                }
                if (isset($matched['error'])) {
                    $send(['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => $matched['error']]);
                    continue;
                }
                $result = match (true) {
                    isset($matched['actions']) => $actions($matched['actions'], (string) $uri, $text, null === $uri ? 1 : ($documents[$uri]['version'] ?? 1)),
                    null !== $matched => $matched['result'] ?? null,
                    'initialize' === $method => ['serverInfo' => ['name' => 'scripted', 'version' => 'test']],
                    'workspace/executeCommand' === $method => [['source' => ['state' => 'ready'], 'runtime' => ['state' => 'ready']]],
                    default => null,
                };
                $send(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $substitute($result, [
                    '{rootUri}' => $script['rootUri'] ?? '',
                    '{uri}' => (string) $uri,
                ])]);
            }
            if ('exit' === $method) {
                break;
            }
        }

        PHP;
}
