<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\Range;

final class StimulusControllerSource
{
    /** @param list<StimulusMember> $members */
    public function __construct(
        public readonly Range $range,
        public readonly array $members,
        public readonly bool $lazy,
    ) {
    }

    /** @return list<string> */
    public function memberNames(StimulusMemberKind $kind): array
    {
        $names = [];
        foreach ($this->members as $member) {
            if ($kind === $member->kind) {
                $names[$member->name] = true;
            }
        }
        $names = array_keys($names);
        sort($names);

        return $names;
    }
}
