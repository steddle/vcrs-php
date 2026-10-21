<?php

namespace Steddle\Vcrs;

/**
 * Grades every record of a register, measures coverage, computes the snapshot root,
 * and tells which level's export conditions hold.
 */
final class Check
{
    public static function run(Register $register, Annex $annex): Report
    {
        $data = $register->data;
        $findings = [];

        if ($annex->optInResidents === 'action' && $data['opt_in_residents'] === null) {
            $findings['register_consistent'][] = "Annex {$annex->code} leaves it to the action whether residents opt in, and the export does not state it.";
        }

        if (! $annex->optInWindow) {
            foreach (['opt_in_opened_on', 'opt_in_closed_on', 'opt_in_channel'] as $field) {
                if ($data[$field] !== null) {
                    $findings['register_consistent'][] = "Annex {$annex->code} has no opt-in window, and the export states {$field}.";
                }
            }
        }

        $annex = $annex->forAction($data['action']['directive'], $data['opt_in_residents']);
        $grader = new Grader($register->informing(), $annex, $annex->optInDue($data['opt_in_closed_on'], $data['reference_date']), Window::from($data));

        $gradings = [];
        $digests = [];

        foreach ($register->records as $index => $raw) {
            $record = $data['records'][$index];
            $id = $record['id'];

            if (isset($gradings[$id])) {
                $findings['register_consistent'][] = "Record id {$id} appears more than once.";

                continue;
            }

            $gradings[$id] = $grader->grade($record);
            $digests[$id] = Canonical::digest($raw);

            if (isset($record['grade']) && $record['grade'] !== $gradings[$id]->grade->value) {
                $findings['register_consistent'][] = "Record {$id} states grade {$record['grade']}; its facts give {$gradings[$id]->grade->value}.";
            }

            if (! isset($record['digest'])) {
                $findings['record_digests'][] = "Record {$id} has no digest.";
            } elseif ($record['digest'] !== $digests[$id]) {
                $findings['record_digests'][] = "Record {$id} states digest {$record['digest']}; its canonical form gives {$digests[$id]}.";
            }
        }

        $records = array_column($data['records'], null, 'id');
        $root = Canonical::root($digests);

        $findings = array_merge_recursive(
            $findings,
            self::consistency($data, $records),
            self::provenance($register, $records, $gradings),
            self::anchor($data, $root),
            self::spread($data, $records, $gradings),
        );

        return new Report($data, $annex, $gradings, $digests, $root, $findings);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $records
     * @return array<string, list<string>>
     */
    private static function consistency(array $data, array $records): array
    {
        $findings = [];
        $flows = array_column($data['flows'], 'id');
        $terms = array_column($data['terms'], 'id');

        if ($data['denominator']['low'] > $data['denominator']['high']) {
            $findings[] = 'The denominator\'s low end is above its high end.';
        }

        $opened = $data['opt_in_opened_on'];
        $closed = $data['opt_in_closed_on'];

        if ($closed !== null && ($opened === null || $opened > $closed)) {
            $findings[] = "The opt-in window closed on {$closed} but states no opening day on or before it.";
        }

        $keys = [];

        foreach ($records as $id => $record) {
            if (! in_array($record['flow'], $flows, true)) {
                $findings[] = "Record {$id} names flow version {$record['flow']}, which the register does not hold.";
            }

            if (! in_array($record['terms'], $terms, true)) {
                $findings[] = "Record {$id} names terms version {$record['terms']}, which the register does not hold.";
            }

            if ($record['duplicate_of'] !== null && ! isset($records[$record['duplicate_of']])) {
                $findings[] = "Record {$id} is marked a duplicate of {$record['duplicate_of']}, which the register does not hold.";
            }

            if (isset($record['dedupe_key']) && $record['status'] === 'active' && $record['duplicate_of'] === null) {
                $keys[$record['dedupe_key']][] = $id;
            }
        }

        foreach ($keys as $ids) {
            if (count($ids) > 1) {
                $findings[] = 'Records '.implode(', ', $ids).' share a deduplication key and none is marked a duplicate.';
            }
        }

        return $findings === [] ? [] : ['register_consistent' => $findings];
    }

    /**
     * @param  array<string, array<string, mixed>>  $records
     * @param  array<string, Grading>  $gradings
     * @return array<string, list<string>>
     */
    private static function provenance(Register $register, array $records, array $gradings): array
    {
        $findings = [];
        $usedFlows = [];
        $usedTerms = [];

        foreach ($records as $id => $record) {
            if ($record['status'] === 'active') {
                $usedFlows[$record['flow']] = true;
                $usedTerms[$record['terms']] = true;
            }
        }

        foreach (array_keys($usedFlows) as $id) {
            $capture = $register->flow($id)['capture'] ?? null;

            if ($capture === null) {
                $findings['flows_captured'][] = "Flow version {$id} was not captured.";
            } elseif ($capture['digest'] === null) {
                $findings['flow_digests'][] = "Flow version {$id} has no capture digest.";
            }

            if (($capture['witnessed'] ?? null) === null) {
                $findings['flows_witnessed'][] = "Flow version {$id} was not witnessed by a bailiff or notary.";
            }
        }

        foreach ($register->data['terms'] as $terms) {
            if (isset($usedTerms[$terms['id']]) && ($terms['digest'] ?? null) === null) {
                $findings['terms_digests'][] = "Terms version {$terms['id']} has no digest.";
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    private static function anchor(array $data, string $root): array
    {
        if (! isset($data['anchor'])) {
            return ['snapshot_anchored' => ["The snapshot root {$root} is not anchored."]];
        }

        if ($data['anchor']['root'] !== $root) {
            return ['snapshot_anchored' => ["The anchored root {$data['anchor']['root']} is not the root of these records, {$root}."]];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $records
     * @param  array<string, Grading>  $gradings
     * @return array<string, list<string>>
     */
    private static function spread(array $data, array $records, array $gradings): array
    {
        $findings = [];
        $facets = $data['facets'] ?? [];
        $subgroups = $data['subgroups'] ?? null;

        if ($facets === []) {
            $findings['cross_section'][] = 'The register declares no facets of the class.';
        }

        if ($subgroups === null) {
            $findings['subgroups'][] = 'The register does not declare its subgroups, or that it has none.';
        }

        foreach ($records as $id => $record) {
            if (! $gradings[$id]->grade->atLeast(Grade::C)) {
                continue;
            }

            foreach ($facets as $facet => $values) {
                $value = $record['facets'][$facet] ?? null;

                if (! array_key_exists((string) $value, $values)) {
                    $findings['cross_section'][] = "Record {$id} has no declared value for facet {$facet}.";
                }
            }

            if ($subgroups !== [] && $subgroups !== null && ! in_array($record['subgroup'] ?? null, $subgroups, true)) {
                $findings['subgroups'][] = "Record {$id} has no declared subgroup.";
            }
        }

        return $findings;
    }
}
