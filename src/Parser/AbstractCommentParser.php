<?php

namespace Symfony\Lsp\Parser;

abstract class AbstractCommentParser implements CommentParserInterface
{
    private ?string $lastSource = null;
    private ?CommentParseResult $lastResult = null;

    final public function parse(string $source): CommentParseResult
    {
        if ($source === $this->lastSource && null !== $this->lastResult) {
            return $this->lastResult;
        }

        $result = $this->parseSource($source);
        $this->lastSource = $source;

        return $this->lastResult = $result;
    }

    final public function mask(string $source): string
    {
        return $this->parse($source)->masked;
    }

    final public function comments(string $source): array
    {
        return $this->parse($source)->comments;
    }

    abstract protected function parseSource(string $source): CommentParseResult;

    final protected function maskRange(string &$masked, string $source, int $start, int $end): void
    {
        for ($offset = $start; $offset < $end; ++$offset) {
            $byte = $source[$offset];
            if ("\r" !== $byte && "\n" !== $byte && \ord($byte) < 0x80) {
                $masked[$offset] = ' ';
            }
        }
    }
}
