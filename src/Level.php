<?php

namespace Steddle\Vcrs;

enum Level: string
{
    case Registered = 'registered';
    case Identified = 'identified';
    case Verified = 'verified';

    /**
     * The conditions of section 10 a facts export can show, each the condition a Finding names. The rest
     * (regeneration before each hearing, the auditor's check, the representative's control) the conformance
     * statement attests.
     *
     * @return list<string>
     */
    public function conditions(): array
    {
        return match ($this) {
            self::Registered => ['flows_captured', 'snapshot_anchored', 'residence_sourced', 'opt_ins_named', 'parts_sized'],
            self::Identified => [...self::Registered->conditions(), 'flow_digests', 'terms_digests', 'record_digests', 'cross_section'],
            self::Verified => [...self::Identified->conditions(), 'flows_witnessed', 'subgroups'],
        };
    }

    public function check(): string
    {
        return match ($this) {
            self::Registered => 'sample_tracing',
            self::Identified => 'full_recomputation',
            self::Verified => 'reproduction_test',
        };
    }
}
