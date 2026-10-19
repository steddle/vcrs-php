<?php

namespace Steddle\Vcrs;

use InvalidArgumentException;
use stdClass;

/**
 * Records from a spreadsheet, one row each, in the columns of templates/records.csv.
 * A column named facet.<name> holds that facet's value; evidence is type:digest pairs separated by |.
 */
final class Csv
{
    private const COLUMNS = ['id', 'party', 'status', 'joined_on', 'flow', 'terms', 'name', 'birth_date', 'registration_number', 'contact_confirmed', 'verified_scheme', 'verified_assurance', 'verified_on', 'basis', 'in_class', 'evidence', 'checked_against', 'duplicate_of'];

    /**
     * @return list<stdClass>
     */
    public static function records(string $path): array
    {
        $handle = is_file($path) ? fopen($path, 'r') : false;

        if ($handle === false) {
            throw new InvalidArgumentException("No file at {$path}.");
        }

        try {
            $header = array_map(trim(...), fgetcsv($handle, escape: '') ?: []);
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');

            if (! mb_check_encoding(implode(',', $header), 'UTF-8')) {
                throw new InvalidRegister(['/records: the header is not UTF-8. Save the CSV as CSV UTF-8.']);
            }

            $missing = array_diff(self::COLUMNS, $header);

            if ($missing !== []) {
                throw new InvalidRegister(['/records: the CSV has no column '.implode(', ', $missing).'. Columns are separated by commas.']);
            }

            $records = [];

            while (($row = fgetcsv($handle, escape: '')) !== false) {
                if ($row === [null]) {
                    continue;
                }

                $pointer = '/records/'.count($records);

                if (count($row) > count($header)) {
                    throw new InvalidRegister(["{$pointer}: the row has more fields than the header."]);
                }

                if (! mb_check_encoding(implode(',', $row), 'UTF-8')) {
                    throw new InvalidRegister(["{$pointer}: the row is not UTF-8. Save the CSV as CSV UTF-8."]);
                }

                try {
                    $records[] = self::record(array_combine($header, array_pad(array_map(trim(...), $row), count($header), '')));
                } catch (InvalidArgumentException $invalid) {
                    throw new InvalidRegister(["{$pointer}: {$invalid->getMessage()}"]);
                }
            }

            return $records;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private static function record(array $row): stdClass
    {
        $verified = $row['verified_scheme'] === '' ? null : [
            'scheme' => $row['verified_scheme'],
            'assurance' => $row['verified_assurance'],
            'on' => $row['verified_on'],
        ];

        $identity = ['name' => self::bool($row, 'name'), 'contact_confirmed' => self::bool($row, 'contact_confirmed'), 'verified' => $verified];
        $identity += $row['party'] === 'legal'
            ? ['registration_number' => self::bool($row, 'registration_number')]
            : ['birth_date' => self::bool($row, 'birth_date')];

        $record = [
            'id' => $row['id'],
            'party' => $row['party'],
            'status' => $row['status'],
            'joined_on' => $row['joined_on'],
            'flow' => $row['flow'],
            'terms' => $row['terms'],
            'identity' => $identity,
            'membership' => [
                'basis' => $row['basis'],
                'in_class' => $row['in_class'] === '' ? null : self::bool($row, 'in_class'),
                'evidence' => self::evidence($row['evidence']),
                'checked_against' => $row['checked_against'] === '' ? null : $row['checked_against'],
            ],
            'duplicate_of' => $row['duplicate_of'] === '' ? null : $row['duplicate_of'],
        ];

        if (($row['residence_country'] ?? '') !== '') {
            $record['residence'] = ['country' => $row['residence_country'], 'on' => $row['residence_on'] ?? ''];
        }

        if (($row['opt_in_on'] ?? '') !== '') {
            $record['opt_in'] = ['on' => $row['opt_in_on'], 'channel' => $row['opt_in_channel'] ?? ''];
        }

        if (($row['parallel_declared'] ?? '') !== '') {
            $record['parallel_declared'] = self::bool($row, 'parallel_declared');
        }

        $facets = [];

        foreach ($row as $column => $value) {
            if (str_starts_with($column, 'facet.') && $value !== '') {
                $facets[substr($column, 6)] = $value;
            }
        }

        $optional = [
            'dedupe_key' => $row['dedupe_key'] ?? '',
            'facets' => $facets,
            'subgroup' => $row['subgroup'] ?? '',
            'grade' => $row['grade'] ?? '',
            'digest' => $row['digest'] ?? '',
        ];

        foreach ($optional as $key => $value) {
            if ($value !== '' && $value !== []) {
                $record[$key] = $value;
            }
        }

        return json_decode(json_encode($record, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Spreadsheets in Dutch and Portuguese write a boolean cell as WAAR or VERDADEIRO.
     *
     * @param  array<string, string>  $row
     */
    private static function bool(array $row, string $column): bool
    {
        return match (mb_strtolower($row[$column])) {
            '1', 'true', 'yes', 'y', 'ja', 'j', 'sim', 'waar', 'verdadeiro' => true,
            '', '0', 'false', 'no', 'n', 'nee', 'não', 'onwaar', 'falso' => false,
            default => throw new InvalidArgumentException("{$column} holds '{$row[$column]}', which is neither yes nor no."),
        };
    }

    /**
     * @return list<array{type: string, digest: string}>
     */
    private static function evidence(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_map(function (string $pair) {
            [$type, $digest] = explode(':', $pair, 2) + [1 => ''];

            return ['type' => $type, 'digest' => $digest];
        }, explode('|', $value));
    }
}
