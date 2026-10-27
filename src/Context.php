<?php

namespace Steddle\Vcrs;

/**
 * What grades a record beside its own facts: the annex as it applies to the action, the reference date, the flow
 * versions, and the action's opt-in settings (section 4.6), as the export states them or a vector's context does.
 */
final readonly class Context
{
    /**
     * @param  array<string, array{informs: bool, live_from: string, live_to: string|null}>  $flows  per flow version id
     * @param  list<array{opened_on: string, closed_on: string|null, channel: string|null, subgroups?: list<string>}>  $terms  the terms the court or the law sets for opting in
     * @param  bool|array<string, bool>|null  $residents  whether residents opt in, where the annex leaves it to the action: for the whole action, or per subgroup
     * @param  list<string>|null  $parts  the parts of a claim that falls under two laws, or null where one law governs it
     */
    public function __construct(
        public Annex $annex,
        public ?string $referenceDate,
        public array $flows,
        public array $terms = [],
        public bool|array|null $residents = null,
        public ?string $residenceOn = null,
        public ?array $parts = null,
        public ?string $courtReference = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the export
     */
    public static function ofExport(array $data, Annex $annex): self
    {
        $flows = [];

        foreach ($data['flows'] as $flow) {
            $flows[$flow['id']] ??= ['informs' => ! in_array(false, $flow['states'], true), 'live_from' => $flow['live_from'], 'live_to' => $flow['live_to']];
        }

        return new self(
            $annex->forAction($data['action']['directive']),
            $data['reference_date'],
            $flows,
            $data['opt_in_terms'],
            $data['opt_in_residents'],
            $data['residence_on'] ?? null,
            $data['parts'] ?? null,
            $data['action']['court_reference'] ?? null,
        );
    }

    /**
     * Whether a fact dated `$date` counts at the reference date: one dated after it does not (section 4.1).
     */
    public function holds(?string $date): bool
    {
        return $date !== null && ($this->referenceDate === null || $date <= $this->referenceDate);
    }

    /**
     * Rule 1: a withdrawal, opt-out or exclusion dated after the reference date leaves the record active at it.
     *
     * @param  array<string, mixed>  $record
     */
    public function active(array $record): bool
    {
        return $record['status'] === 'active' || (isset($record['status_on']) && ! $this->holds($record['status_on']));
    }

    public function informs(string $flow): bool
    {
        return $this->flows[$flow]['informs'] ?? false;
    }

    public function live(string $flow, string $date): bool
    {
        $live = $this->flows[$flow] ?? null;

        return $live !== null && $live['live_from'] <= $date && ($live['live_to'] === null || $date <= $live['live_to']);
    }

    public function residentsOptIn(?string $subgroup): bool
    {
        if (is_bool($this->annex->optInResidents)) {
            return $this->annex->optInResidents;
        }

        if (is_array($this->residents)) {
            return $subgroup === null || ($this->residents[$subgroup] ?? true);
        }

        return $this->residents ?? true;
    }

    /**
     * Whether the record is in the part of the claim the annex's opt-in governs, or one law governs the whole claim.
     *
     * @param  array<string, mixed>  $record
     */
    public function inOptInPart(array $record): bool
    {
        return $this->annex->optInPart === null || $this->parts === null || in_array($this->annex->optInPart, $record['parts'] ?? [], true);
    }

    /**
     * Whether rule 8's opt-in weighs on the grade: on the record wherever it is in the part the annex's opt-in
     * governs, and on a part only where it is that part (section 4.6).
     *
     * @param  array<string, mixed>  $record
     */
    public function optInGrades(array $record, ?string $part): bool
    {
        if ($part === null || $this->annex->optInPart === null || $this->parts === null) {
            return $this->inOptInPart($record);
        }

        return $part === $this->annex->optInPart;
    }

    /**
     * Whether the record's residence decides whether the person opts in: the annex binds one of people abroad and
     * residents on an opt-in, not both.
     *
     * @param  array<string, mixed>  $record
     */
    public function residenceDecides(array $record): bool
    {
        return $this->annex->forum !== null
            && $this->inOptInPart($record)
            && ($this->annex->optInAbroad xor $this->residentsOptIn($record['subgroup'] ?? null));
    }

    /**
     * @return list<array{opened_on: string, closed_on: string|null, channel: string|null, subgroups?: list<string>}>
     */
    public function termsFor(?string $subgroup): array
    {
        return array_values(array_filter($this->terms, fn (array $term): bool => ! isset($term['subgroups']) || in_array($subgroup, $term['subgroups'], true)));
    }

    /**
     * Whether a record that must opt in needs its opt-in at the reference date: from the start where the annex has no
     * window; where it has one, once every term the court set for the record has closed.
     */
    public function optInDue(?string $subgroup): bool
    {
        if (! $this->annex->optInWindow) {
            return true;
        }

        $terms = $this->termsFor($subgroup);
        $closed = array_column($terms, 'closed_on');

        return $terms !== [] && ! in_array(null, $closed, true) && $this->referenceDate !== null && $this->referenceDate > max($closed);
    }
}
