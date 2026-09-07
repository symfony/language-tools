<?php

namespace Symfony\Lsp\Parser\Twig;

final class TwigDirectiveLocator
{
    public function insideDirective(string $text, int $offset): bool
    {
        return null !== $this->directiveStart($text, $offset);
    }

    /** Byte offset of the opening marker of the directive still open at $offset. */
    public function directiveStart(string $text, int $offset): ?int
    {
        [, $inside, $start] = $this->locate($text, $offset);

        return $inside ? $start : null;
    }

    /** @return list<array{start: int, end: int}> */
    public function ranges(string $text): array
    {
        [$ranges, $inside, $start] = $this->locate($text, \strlen($text));
        if ($inside && null !== $start) {
            $ranges[] = ['start' => $start, 'end' => \strlen($text)];
        }

        return $ranges;
    }

    /** @return iterable<array{start: int, end: int}> */
    public function recoveryRanges(string $text): iterable
    {
        preg_match_all('/(?:^|[\r\n])[^\r\n]*?(\{\{|\{%)/', $text, $matches, \PREG_OFFSET_CAPTURE);
        $starts = array_column($matches[1], 1);
        $starts[] = \strlen($text);
        for ($index = 0, $count = \count($starts) - 1; $index < $count; ++$index) {
            $offset = $starts[$index];
            $fragment = substr($text, $offset, $starts[$index + 1] - $offset);
            [$ranges, $inside, $start] = $this->locate($fragment, \strlen($fragment));
            foreach ($ranges as $range) {
                yield ['start' => $offset + $range['start'], 'end' => $offset + $range['end']];
            }
            if ($inside && null !== $start) {
                yield ['start' => $offset + $start, 'end' => $offset + $start + strcspn($fragment, "\r\n", $start)];
            }
        }
    }

    /** @return array{list<array{start: int, end: int}>, bool, int|null} */
    private function locate(string $text, int $limit): array
    {
        $ranges = [];
        $start = null;
        $close = null;
        $quote = null;
        $escaped = false;
        $brackets = [];
        for ($cursor = 0; $cursor < $limit; ++$cursor) {
            $character = $text[$cursor];
            $pair = substr($text, $cursor, 2);
            if (null === $close) {
                if ('{{' === $pair) {
                    $start = $cursor;
                    $close = '}}';
                    $brackets = [];
                    ++$cursor;
                } elseif ('{%' === $pair) {
                    $start = $cursor;
                    $close = '%}';
                    $brackets = [];
                    ++$cursor;
                }
                continue;
            }
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }
                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
            } elseif (\in_array($character, ['(', '[', '{'], true)) {
                $brackets[] = ['(' => ')', '[' => ']', '{' => '}'][$character];
            } elseif ([] !== $brackets && $character === $brackets[array_key_last($brackets)]) {
                array_pop($brackets);
            } elseif ([] === $brackets && $close === $pair) {
                $ranges[] = ['start' => $start ?? $cursor, 'end' => $cursor + 2];
                $start = null;
                $close = null;
                ++$cursor;
            }
        }

        return [$ranges, null !== $close, $start];
    }
}
