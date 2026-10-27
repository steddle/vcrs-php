<?php

namespace Steddle\Vcrs;

use InvalidArgumentException;

/**
 * The conformance statement of section 11.2, drafted from a check. The auditor's part is filled in once the auditor
 * has reported; until then it is null and the statement is a draft.
 */
final class Statement
{
    /**
     * @param  array{firm: string, standard: string, engagement: string, report_date: string}|null  $auditor
     */
    public static function draft(Report $report, ?Level $level = null, ?array $auditor = null, ?string $issuedOn = null): array
    {
        if (! $report->consistent()) {
            throw new InvalidArgumentException('The export is not consistent, so it has no statement to make.');
        }

        if (! $report->verified()) {
            throw new InvalidArgumentException('The export does not verify, so it has no statement to make.');
        }

        $reached = $report->level();

        if ($reached === null) {
            throw new InvalidArgumentException('The register meets no level, so it has no statement to make.');
        }

        $level ??= $reached;

        if (array_search($level, Level::cases(), true) > array_search($reached, Level::cases(), true)) {
            throw new InvalidArgumentException("The register meets {$reached->value}, not {$level->value}.");
        }

        $data = $report->data;
        $issuedOn ??= date('Y-m-d');

        if ($auditor !== null && $auditor['report_date'] < $data['reference_date']) {
            throw new InvalidArgumentException("The auditor's report is dated {$auditor['report_date']}, before the reference date {$data['reference_date']} it reports on.");
        }

        if ($issuedOn < max($data['reference_date'], $auditor['report_date'] ?? '')) {
            throw new InvalidArgumentException("The statement is issued on {$issuedOn}, before the reference date or the auditor's report it rests on.");
        }

        $anchor = $data['anchor'] ?? null;

        $statement = [
            'vcrs' => Vcrs::VERSION,
            'annex' => $report->annex->code,
            'level' => $level->value,
            'draft' => $auditor === null,
            'representative' => $data['representative']['name'],
            'action' => $data['action'],
            'operator' => $data['operator'],
            'reference_date' => $data['reference_date'],
            'denominator' => array_intersect_key($data['denominator'], array_flip(['low', 'high', 'source', 'as_of'])),
            ...(isset($data['claims']) ? ['claims' => $data['claims']] : []),
            'counts' => $report->counts(),
            'coverage' => $report->coverage(),
            'snapshot' => [
                'root' => $report->root,
                'manifest' => $report->manifestDigest,
                'records' => count($report->gradings),
                'anchor' => $anchor === null ? null : array_intersect_key($anchor, array_flip(['method', 'reference', 'on'])),
            ],
            'auditor' => $auditor === null ? null : $auditor + ['check' => $level->check()],
            'issued_on' => $issuedOn,
        ];

        $errors = Schema::errors(json_decode(json_encode($statement, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR), 'conformance');

        if ($errors !== []) {
            throw new InvalidArgumentException("The statement does not match its schema:\n".implode("\n", $errors));
        }

        return $statement;
    }
}
