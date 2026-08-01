<?php

namespace Symfony\Lsp\Parser\TreeSitter;

final class SidecarTreeSitterParser implements TreeSitterParserInterface
{
    public function __construct(
        private readonly string $executable,
        private readonly TreeSitterResultDecoder $decoder,
    ) {
    }

    public function parse(string $language, string $source): TreeSitterTree
    {
        if (!is_file($this->executable) || !is_executable($this->executable)) {
            throw new \RuntimeException('The Symfony LSP Tree-sitter sidecar is not executable.');
        }

        $process = proc_open(
            [$this->executable, $language],
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w'],
            ],
            $pipes,
            options: ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Unable to start the Symfony LSP Tree-sitter sidecar.');
        }

        $written = 0;
        while ($written < \strlen($source)) {
            $bytes = fwrite($pipes[0], substr($source, $written));
            if (false === $bytes || 0 === $bytes) {
                fclose($pipes[0]);
                proc_terminate($process);
                proc_close($process);

                throw new \RuntimeException('Unable to send source to the Symfony LSP Tree-sitter sidecar.');
            }
            $written += $bytes;
        }
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if (0 !== $status || false === $output) {
            throw new \RuntimeException('The Symfony LSP Tree-sitter sidecar failed: '.trim(false === $error ? '' : $error));
        }

        try {
            $result = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The Symfony LSP Tree-sitter sidecar returned invalid JSON.', previous: $exception);
        }

        return $this->decoder->decode($result, \strlen($source));
    }
}
