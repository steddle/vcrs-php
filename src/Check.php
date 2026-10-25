<?php

namespace Steddle\Vcrs;

/**
 * Grades every record of a register, measures coverage, computes the snapshot root and the manifest, and finds what
 * keeps the export from being consistent, from verifying, or from meeting a level's conditions (section 11.6).
 */
final class Check
{
    /**
     * @var list<array{code: Finding, pointer: string, message: string}>
     */
    private array $findings = [];

    private function __construct(
        private readonly Register $register,
        private readonly Context $context,
    ) {}

    public static function run(Register $register, Annex $annex): Report
    {
        $check = new self($register, Context::ofExport($register->data, $annex));
        $grader = new Grader($check->context);
        $gradings = [];
        $digests = [];

        foreach ($register->records as $index => $raw) {
            $record = $register->data['records'][$index];
            $id = $record['id'];

            if (isset($gradings[$id])) {
                $check->find(Finding::DuplicateRecordId, "/records/{$index}/id", "Record id {$id} appears more than once.");

                continue;
            }

            $gradings[$id] = $grader->grade($record);
            $digests[$id] = Canonical::digest($raw);

            if (isset($record['grade']) && $record['grade'] !== $gradings[$id]->grade->value) {
                $check->find(Finding::GradeDiffers, "/records/{$index}/grade", "Record {$id} states grade {$record['grade']}; its facts give {$gradings[$id]->grade->value}.");
            }

            if (! isset($record['digest'])) {
                $check->find(Finding::RecordWithoutDigest, "/records/{$index}", "Record {$id} has no digest.");
            } elseif ($record['digest'] !== $digests[$id]) {
                $check->find(Finding::DigestDiffers, "/records/{$index}/digest", "Record {$id} states digest {$record['digest']}; its canonical form gives {$digests[$id]}.");
            }
        }

        $root = Canonical::root($digests);
        $manifest = Canonical::manifest($register->document, $root);
        $manifestDigest = Canonical::manifestDigest($manifest);

        $check->settings();
        $check->records();
        $check->provenance();
        $check->anchor($root, $manifestDigest);
        $check->spread($gradings);

        return new Report($register->data, $check->context, $gradings, $digests, $root, $manifest, $manifestDigest, $check->findings);
    }

    private function find(Finding $code, string $pointer, string $message): void
    {
        $this->findings[] = ['code' => $code, 'pointer' => $pointer, 'message' => $message];
    }

    /**
     * The export's own settings, flows and terms, and its ranges.
     */
    private function settings(): void
    {
        $data = $this->register->data;
        $annex = $this->context->annex;
        $subgroups = $data['subgroups'] ?? [];

        if ($annex->optInResidents === 'action' && $data['opt_in_residents'] === null) {
            $this->find(Finding::ResidentsUnstated, '/opt_in_residents', "Annex {$annex->code} leaves it to the action whether residents opt in, and the export does not state it.");
        }

        if (is_array($data['opt_in_residents'])) {
            foreach (array_keys($data['opt_in_residents']) as $subgroup) {
                if (! in_array($subgroup, $subgroups, true)) {
                    $this->find(Finding::UnknownSubgroup, '/opt_in_residents/'.$subgroup, "Residents' opt-in is stated for subgroup {$subgroup}, which the export does not declare.");
                }
            }
        }

        if ($data['opt_in_terms'] !== [] && ! $annex->optInAbroad && $annex->optInResidents === false) {
            $this->find(Finding::OptInTermsWithoutOptIn, '/opt_in_terms', "The export states opt-in terms, and Annex {$annex->code} binds nobody on an opt-in in this action.");
        }

        foreach ($data['opt_in_terms'] as $index => $term) {
            if ($term['channel'] !== null && ! $annex->optInWindow) {
                $this->find(Finding::TermChannelWithoutWindow, "/opt_in_terms/{$index}/channel", "Annex {$annex->code} has no opt-in window, so the law names the channel, and opt-in term {$index} states one.");
            }

            if ($term['closed_on'] !== null && $term['closed_on'] < $term['opened_on']) {
                $this->find(Finding::OptInTermInverted, "/opt_in_terms/{$index}", "Opt-in term {$index} closes on {$term['closed_on']}, before it opens on {$term['opened_on']}.");
            }

            foreach ($term['subgroups'] ?? [] as $subgroup) {
                if (! in_array($subgroup, $subgroups, true)) {
                    $this->find(Finding::UnknownSubgroup, "/opt_in_terms/{$index}/subgroups", "Opt-in term {$index} applies to subgroup {$subgroup}, which the export does not declare.");
                }
            }
        }

        $flows = [];

        foreach ($data['flows'] as $index => $flow) {
            if (isset($flows[$flow['id']])) {
                $this->find(Finding::DuplicateFlowId, "/flows/{$index}/id", "Flow version id {$flow['id']} appears more than once.");
            }

            $flows[$flow['id']] = true;

            if ($flow['live_to'] !== null && $flow['live_to'] < $flow['live_from']) {
                $this->find(Finding::FlowDatesInverted, "/flows/{$index}", "Flow version {$flow['id']} was replaced on {$flow['live_to']}, before it went live on {$flow['live_from']}.");
            }
        }

        $terms = [];

        foreach ($data['terms'] as $index => $version) {
            if (isset($terms[$version['id']])) {
                $this->find(Finding::DuplicateTermsId, "/terms/{$index}/id", "Terms version id {$version['id']} appears more than once.");
            }

            $terms[$version['id']] = true;
        }

        if ($data['denominator']['low'] > $data['denominator']['high']) {
            $this->find(Finding::DenominatorInverted, '/denominator', "The denominator's low end is above its high end.");
        }

        if (isset($data['claims']) && $data['claims']['low'] > $data['claims']['high']) {
            $this->find(Finding::ClaimsInverted, '/claims', "The represented claims' low end is above their high end.");
        }
    }

    /**
     * Each record against the flows, terms, dates, parts and the other records the export holds.
     */
    private function records(): void
    {
        $data = $this->register->data;
        $records = [];
        $index = [];

        foreach ($data['records'] as $position => $record) {
            $records[$record['id']] ??= $record;
            $index[$record['id']] ??= $position;
        }

        $flows = array_column($data['flows'], 'id');
        $terms = array_column($data['terms'], 'id');
        $parts = $data['parts'] ?? null;
        $keys = [];

        foreach ($records as $id => $record) {
            $pointer = "/records/{$index[$id]}";

            if (! in_array($record['flow'], $flows, true)) {
                $this->find(Finding::UnknownFlow, "{$pointer}/flow", "Record {$id} names flow version {$record['flow']}, which the export does not hold.");
            } elseif (! $this->context->live($record['flow'], $record['joined_on'])) {
                $this->find(Finding::JoinedOutsideFlow, "{$pointer}/joined_on", "Record {$id} joined on {$record['joined_on']}, when flow version {$record['flow']} was not live.");
            }

            if (! in_array($record['terms'], $terms, true)) {
                $this->find(Finding::UnknownTerms, "{$pointer}/terms", "Record {$id} names terms version {$record['terms']}, which the export does not hold.");
            }

            if ($record['joined_on'] > $data['reference_date']) {
                $this->find(Finding::JoinedAfterReferenceDate, "{$pointer}/joined_on", "Record {$id} joined on {$record['joined_on']}, after the reference date {$data['reference_date']}.");
            }

            if (isset($record['confirmed'])) {
                if (! in_array($record['confirmed']['flow'], $flows, true)) {
                    $this->find(Finding::UnknownFlow, "{$pointer}/confirmed/flow", "Record {$id} confirmed through flow version {$record['confirmed']['flow']}, which the export does not hold.");
                }

                if ($record['confirmed']['on'] < $record['joined_on']) {
                    $this->find(Finding::ConfirmedBeforeJoining, "{$pointer}/confirmed/on", "Record {$id} states a confirmation on {$record['confirmed']['on']}, before it joined on {$record['joined_on']}.");
                }
            }

            $this->duplicate($id, $record, $records, $pointer);

            if (isset($record['dedupe_key']) && $record['status'] === 'active' && $record['duplicate_of'] === null) {
                $keys[$record['dedupe_key']][] = $id;
            }

            if (is_array($data['opt_in_residents']) && $this->context->annex->optInResidents === 'action' && ! array_key_exists($record['subgroup'] ?? '', $data['opt_in_residents'])) {
                $this->find(Finding::ResidentsUnstated, "{$pointer}/subgroup", "Residents' opt-in is stated per subgroup, and none is stated for record {$id}'s.");
            }

            if ($parts !== null && ! isset($record['parts'])) {
                $this->find(Finding::PartsUnstated, $pointer, "The export splits the claim into parts, and record {$id} states none.");
            }

            foreach ($record['parts'] ?? [] as $part) {
                if (! in_array($part, $parts ?? [], true)) {
                    $this->find(Finding::UnknownPart, "{$pointer}/parts", "Record {$id} belongs to part {$part}, which the export does not state.");
                }
            }

            if (isset($record['opt_in']) && $this->context->courtReference !== null && $record['opt_in']['action'] !== $this->context->courtReference) {
                $this->find(Finding::OptInNamesOtherAction, "{$pointer}/opt_in/action", "Record {$id}'s opt-in names {$record['opt_in']['action']}, and the export states the court's reference as {$this->context->courtReference}.");
            }

            if (isset($record['residence']) && ! isset($record['residence']['source']) && $this->context->residenceDecides($record)) {
                $this->find(Finding::ResidenceUnsourced, "{$pointer}/residence", "Record {$id} holds a residence the opt-in turns on, without its source.");
            }
        }

        foreach ($keys as $ids) {
            if (count($ids) > 1) {
                $this->find(Finding::UnmarkedDuplicates, "/records/{$index[$ids[1]]}/dedupe_key", 'Records '.implode(', ', $ids).' share a deduplication key and none is marked a duplicate.');
            }
        }
    }

    /**
     * Section 4.5: a duplicate names an original the export holds, which is not itself a duplicate, and is the later
     * of the two: it joined after the original, or on the same day with an identifier after the original's in byte
     * order.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, array<string, mixed>>  $records
     */
    private function duplicate(string $id, array $record, array $records, string $pointer): void
    {
        $of = $record['duplicate_of'];

        if ($of === null) {
            return;
        }

        $original = $records[$of] ?? null;

        match (true) {
            $of === $id => $this->find(Finding::DuplicateOfItself, "{$pointer}/duplicate_of", "Record {$id} is marked a duplicate of itself."),
            $original === null => $this->find(Finding::UnknownOriginal, "{$pointer}/duplicate_of", "Record {$id} is marked a duplicate of {$of}, which the export does not hold."),
            $original['duplicate_of'] !== null => $this->find(Finding::OriginalIsDuplicate, "{$pointer}/duplicate_of", "Record {$id} is marked a duplicate of {$of}, which is itself marked a duplicate."),
            $original['joined_on'] > $record['joined_on'] || ($original['joined_on'] === $record['joined_on'] && strcmp($of, $id) > 0) => $this->find(Finding::OriginalIsLater, "{$pointer}/duplicate_of", "Record {$id} is marked a duplicate of {$of}, which joined after it."),
            default => null,
        };
    }

    /**
     * Section 5.1 to 5.3, for every flow and terms version an active record joined or confirmed through.
     */
    private function provenance(): void
    {
        $used = ['flows' => [], 'terms' => []];

        foreach ($this->register->data['records'] as $record) {
            if ($record['status'] === 'active') {
                $used['flows'][$record['flow']] = true;
                $used['terms'][$record['terms']] = true;

                if (isset($record['confirmed'])) {
                    $used['flows'][$record['confirmed']['flow']] = true;
                }
            }
        }

        foreach ($this->register->data['flows'] as $index => $flow) {
            if (! isset($used['flows'][$flow['id']])) {
                continue;
            }

            $capture = $flow['capture'];

            if ($capture === null) {
                $this->find(Finding::FlowNotCaptured, "/flows/{$index}/capture", "Flow version {$flow['id']} was not captured.");
            } elseif ($capture['digest'] === null) {
                $this->find(Finding::FlowWithoutDigest, "/flows/{$index}/capture/digest", "Flow version {$flow['id']} has no capture digest.");
            }

            if (($capture['witnessed'] ?? null) === null) {
                $this->find(Finding::FlowNotWitnessed, "/flows/{$index}/capture", "Flow version {$flow['id']} was not witnessed by a bailiff or notary.");
            }
        }

        foreach ($this->register->data['terms'] as $index => $version) {
            if (isset($used['terms'][$version['id']]) && ($version['digest'] ?? null) === null) {
                $this->find(Finding::TermsWithoutDigest, "/terms/{$index}", "Terms version {$version['id']} has no digest.");
            }
        }
    }

    /**
     * Section 5.4: the anchor names this snapshot's root and manifest, and is dated on or after the reference date.
     */
    private function anchor(string $root, string $manifest): void
    {
        $data = $this->register->data;
        $anchor = $data['anchor'] ?? null;

        if ($anchor === null) {
            $this->find(Finding::NotAnchored, '/', "The manifest {$manifest} is not anchored.");

            return;
        }

        if ($anchor['on'] < $data['reference_date']) {
            $this->find(Finding::AnchoredBeforeReferenceDate, '/anchor/on', "The anchor is dated {$anchor['on']}, before the reference date {$data['reference_date']} it fixes.");
        }

        if ($anchor['root'] !== $root) {
            $this->find(Finding::RootDiffers, '/anchor/root', "The anchored root {$anchor['root']} is not the root of these records, {$root}.");
        }

        if ($anchor['manifest'] !== $manifest) {
            $this->find(Finding::ManifestDiffers, '/anchor/manifest', "The anchored manifest {$anchor['manifest']} is not the manifest of this export, {$manifest}.");
        }
    }

    /**
     * Section 6.3 and 6.4, over the records at grade C or better.
     *
     * @param  array<string, Grading>  $gradings
     */
    private function spread(array $gradings): void
    {
        $data = $this->register->data;
        $facets = $data['facets'] ?? [];
        $subgroups = $data['subgroups'] ?? null;

        if ($facets === []) {
            $this->find(Finding::NoFacets, '/facets', 'The register declares no facets of the class.');
        }

        if ($subgroups === null) {
            $this->find(Finding::SubgroupsUndeclared, '/subgroups', 'The register does not declare its subgroups, or that it has none.');
        }

        foreach ($data['records'] as $index => $record) {
            if (! isset($gradings[$record['id']]) || ! $gradings[$record['id']]->grade->atLeast(Grade::C)) {
                continue;
            }

            foreach ($facets as $facet => $values) {
                if (! array_key_exists((string) ($record['facets'][$facet] ?? null), $values)) {
                    $this->find(Finding::FacetValueUndeclared, "/records/{$index}/facets", "Record {$record['id']} has no declared value for facet {$facet}.");
                }
            }

            if ($subgroups !== [] && $subgroups !== null && ! in_array($record['subgroup'] ?? null, $subgroups, true)) {
                $this->find(Finding::SubgroupUndeclared, "/records/{$index}", "Record {$record['id']} has no declared subgroup.");
            }
        }
    }
}
