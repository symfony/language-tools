<?php

namespace Symfony\Lsp\Tools;

final class ReleaseVersion
{
    public function __construct(
        private readonly string $value,
    ) {
        $number = '(?:0|[1-9][0-9]*)';
        $identifier = '(?:'.$number.'|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)';
        if (1 !== preg_match('/^'.$number.'\.'.$number.'\.'.$number.'(?:-'.$identifier.'(?:\.'.$identifier.')*)?$/', $value)) {
            throw new \InvalidArgumentException('The release version must use X.Y.Z or X.Y.Z-PRERELEASE format.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function tag(): string
    {
        return 'v'.$this->value;
    }

    public function isGreaterThan(string $version): bool
    {
        return $this->compare(new self($version)) > 0;
    }

    private function compare(self $other): int
    {
        $leftParts = explode('-', $this->value, 2);
        $rightParts = explode('-', $other->value, 2);
        $leftPrerelease = $leftParts[1] ?? null;
        $rightPrerelease = $rightParts[1] ?? null;

        $leftNumbers = explode('.', $leftParts[0]);
        $rightNumbers = explode('.', $rightParts[0]);
        foreach ($leftNumbers as $index => $leftNumber) {
            $comparison = $this->compareNumericIdentifiers($leftNumber, $rightNumbers[$index]);
            if (0 !== $comparison) {
                return $comparison;
            }
        }

        if ($leftPrerelease === $rightPrerelease) {
            return 0;
        }
        if (null === $leftPrerelease) {
            return 1;
        }
        if (null === $rightPrerelease) {
            return -1;
        }

        $leftIdentifiers = explode('.', $leftPrerelease);
        $rightIdentifiers = explode('.', $rightPrerelease);
        foreach ($leftIdentifiers as $index => $leftIdentifier) {
            if (!isset($rightIdentifiers[$index])) {
                return 1;
            }

            $rightIdentifier = $rightIdentifiers[$index];
            $leftNumeric = ctype_digit($leftIdentifier);
            $rightNumeric = ctype_digit($rightIdentifier);
            if ($leftNumeric && $rightNumeric) {
                $comparison = $this->compareNumericIdentifiers($leftIdentifier, $rightIdentifier);
            } elseif ($leftNumeric) {
                $comparison = -1;
            } elseif ($rightNumeric) {
                $comparison = 1;
            } else {
                $comparison = strcmp($leftIdentifier, $rightIdentifier);
            }
            if (0 !== $comparison) {
                return $comparison;
            }
        }

        return \count($leftIdentifiers) <=> \count($rightIdentifiers);
    }

    private function compareNumericIdentifiers(string $left, string $right): int
    {
        $lengthComparison = \strlen($left) <=> \strlen($right);

        return 0 !== $lengthComparison ? $lengthComparison : strcmp($left, $right);
    }
}
