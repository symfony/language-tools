<?php

namespace Symfony\Lsp\Parser\Twig;

use Symfony\Lsp\Parser\DelimiterScanner;

final class TwigDirectiveLocator
{
    private const TERMINATORS = ['{' => '}}', '%' => '%}'];

    public function insideDirective(string $text, int $offset): bool
    {
        return null !== $this->directiveStart($text, $offset);
    }

    /** Byte offset of the opening marker of the directive still open at $offset. */
    public function directiveStart(string $text, int $offset): ?int
    {
        return $this->locate($text, $offset)[1];
    }

    /** @return list<array{start: int, end: int}> */
    public function ranges(string $text): array
    {
        $length = \strlen($text);
        [$ranges, $open] = $this->locate($text, $length);
        if (null !== $open) {
            $ranges[] = ['start' => $open, 'end' => $length];
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
            [$ranges, $open] = $this->locate($fragment, \strlen($fragment));
            foreach ($ranges as $range) {
                yield ['start' => $offset + $range['start'], 'end' => $offset + $range['end']];
            }
            if (null !== $open) {
                yield ['start' => $offset + $open, 'end' => $offset + $open + strcspn($fragment, "\r\n", $open)];
            }
        }
    }

    /**
     * The directives closed before $limit, and the opening marker of the one still open there.
     *
     * @return array{list<array{start: int, end: int}>, int|null}
     */
    private function locate(string $text, int $limit): array
    {
        $ranges = [];
        $cursor = 0;
        while (false !== $marker = strpos($text, '{', $cursor)) {
            if ($marker >= $limit) {
                break;
            }
            $terminator = self::TERMINATORS[$text[$marker + 1] ?? ''] ?? null;
            if (null === $terminator) {
                $cursor = $marker + 1;
                continue;
            }
            $end = DelimiterScanner::terminator($text, $marker + 2, $terminator, $limit);
            if (null === $end) {
                return [$ranges, $marker];
            }
            $ranges[] = ['start' => $marker, 'end' => $end + 2];
            $cursor = $end + 2;
        }

        return [$ranges, null];
    }
}
