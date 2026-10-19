<?php

namespace Steddle\Vcrs;

final readonly class Grading
{
    /**
     * @param  list<Reason>  $reasons
     */
    public function __construct(
        public Grade $grade,
        public array $reasons = [],
    ) {}

    /**
     * @return list<string>
     */
    public function reasonCodes(): array
    {
        return array_map(fn (Reason $reason) => $reason->value, $this->reasons);
    }
}
