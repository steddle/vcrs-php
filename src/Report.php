<?php

namespace Steddle\Vcrs;

use stdClass;

final readonly class Report
{
    public Annex $annex;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Grading>  $gradings  per record id
     * @param  array<string, array<string, Grading>>  $partGradings  per record id, per part of a split claim it belongs to
     * @param  array<string, string>  $digests  per record id
     * @param  list<array{code: Finding, pointer: string, message: string}>  $findings
     */
    public function __construct(
        public array $data,
        public Context $context,
        public array $gradings,
        public array $partGradings,
        public array $digests,
        public string $root,
        public stdClass $manifest,
        public string $manifestDigest,
        public array $findings,
    ) {
        $this->annex = $context->annex;
    }

    /**
     * Whether the export can be read one way only (section 11.6).
     */
    public function consistent(): bool
    {
        return $this->with('consistent') === [];
    }

    /**
     * Whether what the export states, its grades, digests, root and manifest, is what its facts give (section 11.6).
     */
    public function verified(): bool
    {
        return $this->with('verified') === [];
    }

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
     * Active records at or above each counted grade, as a percentage of each end of the denominator, rounded half up to
     * four decimals (section 6.2).
     *
     * @return array<string, array{records: int, low: float, high: float}>
     */
    public function coverage(): array
    {
        return $this->share($this->counts(), $this->data['denominator']);
    }

    /**
     * Coverage as section 6.2 measures it, per subgroup and per part of a split claim the denominator sizes
     * (sections 4.6 and 6.4).
     *
     * @return array{subgroups: array<string, array<string, array{records: int, low: float, high: float}>>, parts: array<string, array<string, array{records: int, low: float, high: float}>>}
     */
    public function groupCoverage(): array
    {
        $spread = ['subgroups' => $this->subgroups(), 'parts' => $this->parts()];
        $coverage = ['subgroups' => [], 'parts' => []];

        foreach ($spread as $kind => $counts) {
            foreach ($this->data['denominator'][$kind] ?? [] as $group => $size) {
                $coverage[$kind][$group] = $this->share($counts[$group] ?? ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0], $size);
            }
        }

        return $coverage;
    }

    /**
     * @param  array<string, int>  $counts  per grade
     * @param  array{low: int, high: int}  $size
     * @return array<string, array{records: int, low: float, high: float}>
     */
    private function share(array $counts, array $size): array
    {
        $coverage = [];
        $running = 0;

        foreach (Grade::counted() as $grade) {
            $running += $counts[$grade->value];
            $coverage[$grade->value] = [
                'records' => $running,
                'low' => round($running / $size['high'] * 100, 4),
                'high' => round($running / $size['low'] * 100, 4),
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
        $records = $this->records();
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
        return $this->spreadOver($this->data['subgroups'] ?? [], fn (array $record): array => [$record['subgroup'] ?? null]);
    }

    /**
     * Per part of a split claim, active records per grade: a record in both parts counts in each, at the grade it
     * holds in that part (section 4.6).
     *
     * @return array<string, array{A: int, B: int, C: int, D: int}>
     */
    public function parts(): array
    {
        $spread = array_fill_keys($this->data['parts'] ?? [], ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0]);

        foreach ($this->partGradings as $gradings) {
            foreach ($gradings as $part => $grading) {
                if (isset($spread[$part]) && ! in_array(Reason::NotActive, $grading->reasons, true)) {
                    $spread[$part][$grading->grade->value]++;
                }
            }
        }

        return $spread;
    }

    /**
     * The highest level whose export conditions all hold, or null. An export that is not consistent, or does not
     * verify, meets none.
     */
    public function level(): ?Level
    {
        if (! $this->consistent() || ! $this->verified()) {
            return null;
        }

        $reached = null;

        foreach (Level::cases() as $level) {
            foreach ($this->findings as $finding) {
                if (in_array($finding['code']->condition(), $level->conditions(), true)) {
                    return $reached;
                }
            }

            $reached = $level;
        }

        return $reached;
    }

    /**
     * What stands between this export and the next level: what keeps it from being consistent or from verifying, else
     * the conditions of the next level it does not meet.
     *
     * @return list<array{code: Finding, pointer: string, message: string}>
     */
    public function blocking(): array
    {
        if (! $this->consistent() || ! $this->verified()) {
            return [...$this->with('consistent'), ...$this->with('verified')];
        }

        $next = match ($this->level()) {
            null => Level::Registered,
            Level::Registered => Level::Identified,
            Level::Identified, Level::Verified => Level::Verified,
        };

        return array_values(array_filter($this->findings, fn (array $finding): bool => in_array($finding['code']->condition(), $next->conditions(), true)));
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
            'subgroups' => (object) $this->subgroups(),
            'parts' => (object) $this->parts(),
            'group_coverage' => array_map(fn (array $groups): object => (object) $groups, $this->groupCoverage()),
            'snapshot_root' => $this->root,
            'manifest' => $this->manifestDigest,
            'consistent' => $this->consistent(),
            'verified' => $this->verified(),
            'export_conditions_met' => $this->level()?->value,
            'findings' => array_map(fn (array $finding): array => [...$finding, 'code' => $finding['code']->value], $this->findings),
            'grades' => array_map(fn (Grading $grading) => ['grade' => $grading->grade->value, 'reasons' => $grading->reasonCodes()], $this->gradings),
        ];
    }

    /**
     * @return list<array{code: Finding, pointer: string, message: string}>
     */
    private function with(string $outcome): array
    {
        return array_values(array_filter($this->findings, fn (array $finding): bool => $finding['code']->outcome() === $outcome));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function records(): array
    {
        $records = [];

        foreach ($this->data['records'] as $record) {
            $records[$record['id']] ??= $record;
        }

        return $records;
    }

    /**
     * @param  list<string>  $groups
     * @param  callable(array<string, mixed>): list<string|null>  $of
     * @return array<string, array{A: int, B: int, C: int, D: int}>
     */
    private function spreadOver(array $groups, callable $of): array
    {
        $records = $this->records();
        $spread = array_fill_keys($groups, ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0]);

        foreach ($this->gradings as $id => $grading) {
            if (in_array(Reason::NotActive, $grading->reasons, true)) {
                continue;
            }

            foreach ($of($records[$id]) as $group) {
                if ($group !== null && isset($spread[$group])) {
                    $spread[$group][$grading->grade->value]++;
                }
            }
        }

        return $spread;
    }
}
