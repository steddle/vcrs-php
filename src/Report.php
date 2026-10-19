<?php

namespace Steddle\Vcrs;

final readonly class Report
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Grading>  $gradings  per record id
     * @param  array<string, string>  $digests  per record id
     * @param  array<string, list<string>>  $findings  per level condition
     */
    public function __construct(
        public array $data,
        public Annex $annex,
        public array $gradings,
        public array $digests,
        public string $root,
        public array $findings,
    ) {}

    /**
     * Active records per grade, and records not active.
     *
     * @return array{A: int, B: int, C: int, D: int, inactive: int}
     */
    public function counts(): array
    {
        $counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'inactive' => 0];

        foreach ($this->gradings as $grading) {
            in_array(Reason::NotActive, $grading->reasons, true)
                ? $counts['inactive']++
                : $counts[$grading->grade->value]++;
        }

        return $counts;
    }

    /**
     * Active records at or above each counted grade, as a percentage of each end of the denominator.
     *
     * @return array<string, array{records: int, low: float, high: float}>
     */
    public function coverage(): array
    {
        $counts = $this->counts();
        $denominator = $this->data['denominator'];
        $coverage = [];
        $running = 0;

        foreach (Grade::counted() as $grade) {
            $running += $counts[$grade->value];
            $coverage[$grade->value] = [
                'records' => $running,
                'low' => round($running / $denominator['high'] * 100, 4),
                'high' => round($running / $denominator['low'] * 100, 4),
            ];
        }

        return $coverage;
    }

    /**
     * Per grade, per reason code, how many records it applies to.
     *
     * @return array<string, array<string, int>>
     */
    public function reasons(): array
    {
        $reasons = [];

        foreach ($this->gradings as $grading) {
            foreach ($grading->reasonCodes() as $code) {
                $reasons[$grading->grade->value][$code] = ($reasons[$grading->grade->value][$code] ?? 0) + 1;
            }
        }

        ksort($reasons);

        return array_map(function (array $codes) {
            ksort($codes);

            return $codes;
        }, $reasons);
    }

    /**
     * Per facet and value, the records at grade C or better, beside the class's share where the register gives it.
     *
     * @return array<string, array<string, array{records: int, share: float, class: float|null}>>
     */
    public function crossSection(): array
    {
        $records = array_column($this->data['records'], null, 'id');
        $counted = array_filter($this->gradings, fn (Grading $grading) => $grading->grade->atLeast(Grade::C));
        $spread = [];

        foreach ($this->data['facets'] ?? [] as $facet => $values) {
            foreach ($values as $value => $share) {
                $spread[$facet][$value] = ['records' => 0, 'share' => 0.0, 'class' => $share];
            }

            foreach (array_keys($counted) as $id) {
                $value = $records[$id]['facets'][$facet] ?? null;

                if ($value !== null && isset($spread[$facet][$value])) {
                    $spread[$facet][$value]['records']++;
                }
            }

            foreach ($spread[$facet] as $value => $row) {
                $spread[$facet][$value]['share'] = $counted === [] ? 0.0 : round($row['records'] / count($counted), 6);
            }
        }

        return $spread;
    }

    /**
     * Per subgroup, active records per grade.
     *
     * @return array<string, array{A: int, B: int, C: int, D: int}>
     */
    public function subgroups(): array
    {
        $records = array_column($this->data['records'], null, 'id');
        $spread = [];

        foreach ($this->data['subgroups'] ?? [] as $subgroup) {
            $spread[$subgroup] = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        }

        foreach ($this->gradings as $id => $grading) {
            $subgroup = $records[$id]['subgroup'] ?? null;

            if ($subgroup !== null && isset($spread[$subgroup]) && $records[$id]['status'] === 'active') {
                $spread[$subgroup][$grading->grade->value]++;
            }
        }

        return $spread;
    }

    /**
     * The highest level whose export conditions all hold, or null.
     */
    public function level(): ?Level
    {
        $reached = null;

        foreach (Level::cases() as $level) {
            foreach ($level->conditions() as $condition) {
                if (($this->findings[$condition] ?? []) !== []) {
                    return $reached;
                }
            }

            $reached = $level;
        }

        return $reached;
    }

    /**
     * What stands between this register and the next level.
     *
     * @return array<string, list<string>>
     */
    public function blocking(): array
    {
        $next = match ($this->level()) {
            null => Level::Registered,
            Level::Registered => Level::Identified,
            Level::Identified, Level::Verified => Level::Verified,
        };

        return array_filter(
            array_intersect_key($this->findings, array_flip($next->conditions())),
            fn (array $findings) => $findings !== [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'vcrs' => Vcrs::VERSION,
            'annex' => $this->annex->code,
            'reference_date' => $this->data['reference_date'],
            'records' => count($this->gradings),
            'counts' => $this->counts(),
            'coverage' => $this->coverage(),
            'reasons' => $this->reasons(),
            'cross_section' => $this->crossSection(),
            'subgroups' => $this->subgroups(),
            'snapshot_root' => $this->root,
            'export_conditions_met' => $this->level()?->value,
            'findings' => $this->findings,
            'grades' => array_map(fn (Grading $grading) => ['grade' => $grading->grade->value, 'reasons' => $grading->reasonCodes()], $this->gradings),
        ];
    }
}
