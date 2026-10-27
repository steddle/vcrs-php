<?php

use Steddle\Vcrs\Annex;
use Steddle\Vcrs\Canonical;
use Steddle\Vcrs\Check;
use Steddle\Vcrs\Csv;
use Steddle\Vcrs\InvalidRegister;
use Steddle\Vcrs\Level;
use Steddle\Vcrs\Register;
use Steddle\Vcrs\Report;
use Steddle\Vcrs\Statement;
use Steddle\Vcrs\Vcrs;

/**
 * @return array<string, mixed> the example register with its records, as arrays
 */
function templateDocument(): array
{
    $document = json_decode(file_get_contents(template('register.json')), true);
    $document['records'] = array_map(fn ($record) => json_decode(json_encode($record), true), Csv::records(template('records.csv')));

    return $document;
}

function reportOf(array $document, string|Annex $annex = 'nl'): Report
{
    $path = tempnam(sys_get_temp_dir(), 'vcrs').'.json';
    file_put_contents($path, json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return Check::run(Register::read($path), $annex instanceof Annex ? $annex : Annex::load($annex));
}

function templateReport(?callable $change = null, string|Annex $annex = 'nl'): Report
{
    if ($change === null) {
        return Check::run(Register::read(template('register.json'), template('records.csv')), $annex instanceof Annex ? $annex : Annex::load($annex));
    }

    return reportOf($change(templateDocument()), $annex);
}

/**
 * The example register changed, with every record's digest, the root and the manifest recomputed and anchored again,
 * so the check finds only what the change itself causes.
 */
function sealed(callable $change, string|Annex $annex = 'nl'): Report
{
    return reportOf(sealedDocument($change, $annex), $annex);
}

/**
 * @return array<string, mixed>
 */
function sealedDocument(callable $change, string|Annex $annex = 'nl'): array
{
    $document = $change(templateDocument());

    foreach ($document['records'] as $index => $record) {
        unset($record['digest']);
        $document['records'][$index]['digest'] = Canonical::digest(json_decode(json_encode($record)));
    }

    $report = reportOf($document, $annex);
    $document['anchor'] = [...$document['anchor'], 'root' => $report->root, 'manifest' => $report->manifestDigest];

    return $document;
}

/**
 * @return list<string>
 */
function codes(Report $report): array
{
    return array_map(fn (array $finding): string => $finding['code']->value, $report->findings);
}

/**
 * @return array{low: int, high: int, source: string, method: string, as_of: string}
 */
function size(int $low, int $high): array
{
    return ['low' => $low, 'high' => $high, 'source' => 'RDW open data', 'method' => 'Registrations of the models concerned', 'as_of' => '2026-09-01'];
}

it('checks the template register at level verified', function () {
    $report = templateReport();

    expect($report->counts())->toBe(['A' => 1, 'B' => 2, 'C' => 2, 'D' => 2, 'inactive' => 1])
        ->and($report->coverage()['C'])->toBe(['records' => 5, 'low' => round(5 / 26000 * 100, 4), 'high' => round(5 / 21000 * 100, 4)])
        ->and($report->root)->toBe(json_decode(file_get_contents(template('register.json')), true)['anchor']['root'])
        ->and($report->manifestDigest)->toBe(json_decode(file_get_contents(template('register.json')), true)['anchor']['manifest'])
        ->and($report->findings)->toBe([])
        ->and($report->consistent())->toBeTrue()
        ->and($report->verified())->toBeTrue()
        ->and($report->level())->toBe(Level::Verified);
});

it('gives a csv register the same root and manifest as the same records in json', function () {
    $json = templateReport(fn ($document) => $document);

    expect($json->root)->toBe(templateReport()->root)
        ->and($json->manifestDigest)->toBe(templateReport()->manifestDigest);
});

it('reports the spread of counted records over the class facets', function () {
    expect(templateReport()->crossSection()['region'])->toBe([
        'noord' => ['records' => 2, 'share' => 0.4, 'class' => 0.3],
        'midden' => ['records' => 1, 'share' => 0.2, 'class' => 0.45],
        'zuid' => ['records' => 2, 'share' => 0.4, 'class' => 0.25],
    ]);
});

it('finds an export that cannot be read one way, and meets no level', function (Closure $change, string $code, string $annex = 'nl') {
    $report = sealed($change, $annex);

    expect(codes($report))->toContain($code)
        ->and($report->consistent())->toBeFalse()
        ->and($report->level())->toBeNull();
})->with([
    'a record id twice' => [function ($document) {
        $document['records'][] = [...$document['records'][1], 'id' => 'r-0001'];

        return $document;
    }, 'duplicate_record_id'],
    'a flow id twice, the copies disagreeing' => [function ($document) {
        $uninformed = $document['flows'][0];
        $uninformed['states']['period'] = false;
        $document['flows'] = [$uninformed, [...$document['flows'][0], 'capture' => null]];

        return $document;
    }, 'duplicate_flow_id'],
    'a terms id twice' => [function ($document) {
        $document['terms'][] = ['id' => 't1', 'digest' => 'sha256:'.str_repeat('a', 64)];

        return $document;
    }, 'duplicate_terms_id'],
    'a flow the export does not hold' => [function ($document) {
        $document['records'][2]['flow'] = 'f9';
        unset($document['records'][2]['grade']);

        return $document;
    }, 'unknown_flow'],
    'terms the export does not hold' => [function ($document) {
        $document['records'][2]['terms'] = 't9';

        return $document;
    }, 'unknown_terms'],
    'a duplicate of a record the export does not hold' => [function ($document) {
        $document['records'][2]['duplicate_of'] = 'r-9999';
        unset($document['records'][2]['grade']);

        return $document;
    }, 'unknown_original'],
    'a duplicate of itself' => [function ($document) {
        $document['records'][2]['duplicate_of'] = 'r-0003';
        unset($document['records'][2]['grade']);

        return $document;
    }, 'duplicate_of_itself'],
    'two duplicates of each other' => [function ($document) {
        $document['records'][1]['duplicate_of'] = 'r-0003';
        $document['records'][2]['duplicate_of'] = 'r-0002';
        unset($document['records'][1]['grade'], $document['records'][2]['grade']);

        return $document;
    }, 'original_is_duplicate'],
    'the earlier record marked the duplicate' => [function ($document) {
        $document['records'][3]['duplicate_of'] = 'r-0006';
        $document['records'][5]['duplicate_of'] = null;
        unset($document['records'][3]['grade'], $document['records'][5]['grade']);

        return $document;
    }, 'original_is_later'],
    'duplicates nobody marked' => [function ($document) {
        $document['records'][5]['duplicate_of'] = null;
        unset($document['records'][5]['grade']);

        return $document;
    }, 'unmarked_duplicates'],
    'a record that joined after the reference date' => [function ($document) {
        $document['records'][0]['joined_on'] = '2027-06-01';

        return $document;
    }, 'joined_after_reference_date'],
    'a record that joined before its flow version went live' => [function ($document) {
        $document['flows'][0]['live_from'] = '2026-09-05';

        return $document;
    }, 'joined_outside_flow'],
    'a flow version replaced before it went live' => [function ($document) {
        $document['flows'][0]['live_to'] = '2026-08-01';

        return $document;
    }, 'flow_dates_inverted'],
    'a confirmation before joining' => [function ($document) {
        $document['records'][3]['confirmed'] = ['flow' => 'f1', 'on' => '2026-08-01'];

        return $document;
    }, 'confirmed_before_joining'],
    'the denominator inverted' => [function ($document) {
        $document['denominator'] = [...$document['denominator'], 'low' => 26000, 'high' => 21000];

        return $document;
    }, 'denominator_inverted'],
    'the claims inverted' => [function ($document) {
        $document['claims'] = [...$document['claims'], 'low' => 39000000, 'high' => 1];

        return $document;
    }, 'claims_inverted'],
    'a channel on a term where the annex has no window' => [function ($document) {
        $document['annex'] = 'at';
        $document['opt_in_terms'] = [['opened_on' => '2026-09-01', 'closed_on' => '2026-12-01', 'channel' => 'web']];

        foreach ($document['records'] as $index => $record) {
            unset($document['records'][$index]['grade']);
        }

        return $document;
    }, 'term_channel_without_window', 'at'],
    'an opt-in term that closes before it opens' => [function ($document) {
        $document['opt_in_terms'] = [['opened_on' => '2026-10-01', 'closed_on' => '2026-09-01', 'channel' => null]];

        return $document;
    }, 'opt_in_term_inverted'],
    'a term for a subgroup the export does not declare' => [function ($document) {
        $document['opt_in_terms'] = [['opened_on' => '2026-09-01', 'closed_on' => null, 'channel' => null, 'subgroups' => ['lease']]];

        return $document;
    }, 'unknown_subgroup'],
    'residents\' opt-in per subgroup, none for a record\'s' => [function ($document) {
        $document['annex'] = 'be';
        $document['subgroups'] = ['property', 'moral'];
        $document['opt_in_residents'] = ['property' => false, 'moral' => true];

        foreach ($document['records'] as $index => $record) {
            $document['records'][$index]['subgroup'] = 'property';
            unset($document['records'][$index]['grade']);
        }

        unset($document['records'][4]['subgroup']);

        return $document;
    }, 'residents_unstated', 'be'],
    'parts on the export, none on a record' => [function ($document) {
        $document['parts'] = ['pre_wamca', 'wamca'];

        foreach ($document['records'] as $index => $record) {
            $document['records'][$index]['parts'] = ['wamca'];
        }

        unset($document['records'][4]['parts']);

        return $document;
    }, 'parts_unstated'],
    'a part the export does not state' => [function ($document) {
        $document['records'][0]['parts'] = ['wamca'];

        return $document;
    }, 'unknown_part'],
    'a denominator for a subgroup the export does not declare' => [function ($document) {
        $document['denominator']['subgroups'] = ['retail' => size(10, 20)];

        return $document;
    }, 'unknown_subgroup'],
    'a part\'s denominator inverted' => [function ($document) {
        $document['parts'] = ['pre_wamca', 'wamca'];
        $document['denominator']['parts'] = ['pre_wamca' => size(200, 100), 'wamca' => size(21000, 26000)];

        foreach ($document['records'] as $index => $record) {
            $document['records'][$index]['parts'] = ['wamca'];
        }

        return $document;
    }, 'denominator_inverted'],
    'an anchor dated before the reference date' => [function ($document) {
        $document['anchor']['on'] = '2026-10-01';

        return $document;
    }, 'anchored_before_reference_date'],
]);

it('finds an export that does not compute what it states, and meets no level', function (Closure $change, array $codes) {
    $report = templateReport($change);

    expect(codes($report))->toBe($codes)
        ->and($report->verified())->toBeFalse()
        ->and($report->level())->toBeNull();
})->with([
    'a stated grade its facts do not give' => [function ($document) {
        $document['records'][3]['grade'] = 'B';

        return $document;
    }, ['grade_differs', 'digest_differs', 'root_differs', 'manifest_differs']],
    'a record changed after its snapshot' => [function ($document) {
        $document['records'][2]['joined_on'] = '2026-09-03';

        return $document;
    }, ['digest_differs', 'root_differs', 'manifest_differs']],
]);

it('catches a flow version that stops informing under the same root, through the manifest', function () {
    $document = sealedDocument(function ($document) {
        foreach ($document['records'] as $index => $record) {
            unset($document['records'][$index]['grade']);
        }

        return $document;
    });
    $document['flows'][0]['states']['period'] = false;
    $report = reportOf($document);

    expect($report->counts())->toBe(['A' => 0, 'B' => 0, 'C' => 0, 'D' => 7, 'inactive' => 1])
        ->and($report->root)->toBe($document['anchor']['root'])
        ->and(codes($report))->toBe(['manifest_differs'])
        ->and($report->level())->toBeNull();
});

it('changes the manifest, never the root, when the context that grades the records changes', function (Closure $change) {
    $before = templateReport(fn ($document) => $document);
    $after = templateReport($change);

    expect($after->root)->toBe($before->root)
        ->and($after->manifestDigest)->not->toBe($before->manifestDigest)
        ->and(codes($after))->toContain('manifest_differs')
        ->and(codes($after))->not->toContain('root_differs');
})->with([
    'the denominator' => [fn ($document) => [...$document, 'denominator' => [...$document['denominator'], 'low' => 20000]]],
    'the class definition' => [fn ($document) => [...$document, 'class' => [...$document['class'], 'version' => '2']]],
    'the reference date' => [fn ($document) => [...$document, 'reference_date' => '2026-10-13']],
    'the annex' => [fn ($document) => [...$document, 'annex' => 'be']],
    'a flow version\'s states' => [function ($document) {
        $document['flows'][0]['states']['period'] = false;

        return $document;
    }],
    'the opt-in terms' => [fn ($document) => [...$document, 'opt_in_terms' => [['opened_on' => '2026-11-01', 'closed_on' => null, 'channel' => null]]]],
]);

it('stays at identified without a witnessed flow', function () {
    $report = sealed(function ($document) {
        $document['flows'][0]['capture']['witnessed'] = null;

        return $document;
    });

    expect($report->level())->toBe(Level::Identified)
        ->and(array_map(fn (array $finding) => [$finding['code']->value, $finding['pointer']], $report->blocking()))->toBe([['flow_not_witnessed', '/flows/0/capture']]);
});

it('checks a record withdrawn after the reference date as the active record it was then', function (Closure $change, string $code) {
    expect(codes(sealed($change)))->toContain($code);
})->with([
    'its deduplication key' => [function ($document) {
        $document['records'][5]['duplicate_of'] = null;
        unset($document['records'][5]['grade']);
        $document['records'][3] = [...$document['records'][3], 'status' => 'withdrawn', 'status_on' => '2026-11-02'];

        return $document;
    }, 'unmarked_duplicates'],
    'the flow it joined through' => [function ($document) {
        $document['flows'][] = [...$document['flows'][0], 'id' => 'f2', 'capture' => null];
        $document['records'][0] = [...$document['records'][0], 'flow' => 'f2', 'status' => 'withdrawn', 'status_on' => '2026-11-02'];

        return $document;
    }, 'flow_not_captured'],
]);

it('stays at registered without facets', function () {
    $report = sealed(function ($document) {
        unset($document['facets']);

        return $document;
    });

    expect($report->level())->toBe(Level::Registered)
        ->and(codes($report))->toBe(['no_facets']);
});

it('meets no level while a residence the opt-in turns on has no source', function () {
    $report = sealed(function ($document) {
        unset($document['records'][4]['residence']['source']);

        return $document;
    });

    expect(codes($report))->toBe(['residence_unsourced'])
        ->and($report->consistent())->toBeTrue()
        ->and($report->level())->toBeNull();
});

it('reports an opt-in that names another action than the court\'s reference, and grades it as made', function () {
    $opted = function ($document, string $action) {
        $document['action']['court_reference'] = 'C/13/750001 / HA ZA 26-100';
        $document['opt_in_terms'] = [['opened_on' => '2026-09-01', 'closed_on' => '2026-10-01', 'channel' => null]];
        $document['records'][4]['opt_in'] = ['on' => '2026-09-15', 'channel' => 'registry', 'action' => $action];
        $document['records'][4]['parallel_declared'] = true;

        return $document;
    };

    $named = sealed(fn ($document) => $opted($document, 'C/13/750001 / HA ZA 26-100'));
    $other = sealed(fn ($document) => $opted($document, 'HA ZA 26/100'));

    expect($named->gradings['r-0005']->grade->value)->toBe('C')
        ->and($other->gradings['r-0005']->grade->value)->toBe('C')
        ->and(codes($named))->toBe([])
        ->and(codes($other))->toBe(['opt_in_names_other_action'])
        ->and($other->level())->toBeNull();
});

it('drafts a conformance statement its schema accepts, a draft until the auditor reports', function () {
    $draft = Statement::draft(templateReport(), issuedOn: '2026-10-15');
    $statement = Statement::draft(
        templateReport(),
        auditor: ['firm' => 'Voorbeeld Accountants', 'standard' => 'Standaard 4400N', 'engagement' => 'agreed_upon_procedures', 'report_date' => '2026-10-14'],
        issuedOn: '2026-10-15',
    );

    expect($draft['draft'])->toBeTrue()
        ->and($draft['auditor'])->toBeNull()
        ->and($statement['draft'])->toBeFalse()
        ->and($statement['level'])->toBe('verified')
        ->and($statement['auditor']['check'])->toBe('reproduction_test')
        ->and($statement['auditor']['engagement'])->toBe('agreed_upon_procedures')
        ->and($statement['snapshot']['records'])->toBe(8)
        ->and($statement['snapshot']['manifest'])->toBe(templateReport()->manifestDigest);
});

it('counts in the statement the records at grade C whose evidence does not establish membership', function () {
    $report = sealed(function ($document) {
        $document['records'][1]['membership']['in_class'] = null;
        unset($document['records'][1]['grade']);

        return $document;
    });

    expect(Statement::draft($report, issuedOn: '2026-10-15')['membership_not_established'])->toBe(1)
        ->and(Statement::draft(templateReport(), issuedOn: '2026-10-15')['membership_not_established'])->toBe(0);
});

it('fills in the example statement with the statement drafted for the example register', function () {
    $markdown = file_get_contents(template('conformance-statement-example.md'));
    preg_match('/```json\n(.*?)```/s', $markdown, $json);
    $example = json_decode($json[1], true);
    $statement = Statement::draft(templateReport(), auditor: array_diff_key($example['auditor'], ['check' => true]), issuedOn: $example['issued_on']);

    expect($example)->toBe($statement);

    foreach (['A', 'B', 'C'] as $grade) {
        ['records' => $records, 'low' => $low, 'high' => $high] = $statement['coverage'][$grade];

        expect($markdown)->toContain("| {$grade} or better | {$records} | {$low}% to {$high}% |");
    }

    expect($markdown)->toContain("| D, active | {$statement['counts']['D']} | |")
        ->toContain("| D, not active | {$statement['counts']['inactive']} | |")
        ->toContain("| Records in the snapshot | {$statement['snapshot']['records']} |")
        ->toContain("| Snapshot root | {$statement['snapshot']['root']} |")
        ->toContain("| Manifest | {$statement['snapshot']['manifest']} |");
});

it('refuses a statement above the level the register meets', function () {
    $report = sealed(function ($document) {
        $document['flows'][0]['capture']['witnessed'] = null;

        return $document;
    });

    Statement::draft($report, Level::Verified);
})->throws(InvalidArgumentException::class, 'The register meets identified, not verified.');

it('refuses a statement on an export that does not verify', function () {
    Statement::draft(templateReport(function ($document) {
        $document['records'][3]['grade'] = 'B';

        return $document;
    }));
})->throws(InvalidArgumentException::class, 'The export does not verify, so it has no statement to make.');

it('refuses a statement dated before what it rests on', function (array $auditor, string $issuedOn, string $message) {
    expect(fn () => Statement::draft(templateReport(), auditor: $auditor, issuedOn: $issuedOn))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a report before the reference date' => [['firm' => 'Voorbeeld Accountants', 'standard' => 'Standaard 4400N', 'engagement' => 'agreed_upon_procedures', 'report_date' => '2026-10-01'], '2026-10-15', 'before the reference date'],
    'issued before the report' => [['firm' => 'Voorbeeld Accountants', 'standard' => 'Standaard 4400N', 'engagement' => 'agreed_upon_procedures', 'report_date' => '2026-10-14'], '2026-10-13', 'before the reference date or the auditor\'s report'],
]);

it('rejects an export that does not match the schema', function () {
    $path = tempnam(sys_get_temp_dir(), 'vcrs').'.json';
    file_put_contents($path, json_encode(['vcrs' => Vcrs::VERSION]));

    Register::read($path);
})->throws(InvalidRegister::class);

it('counts a record abroad without an opt-in until every term has closed', function (?string $closedOn, string $grade) {
    $report = templateReport(function (array $document) use ($closedOn) {
        $document['opt_in_terms'] = [['opened_on' => '2026-09-01', 'closed_on' => $closedOn, 'channel' => null]];

        return $document;
    });

    expect($report->gradings['r-0005']->grade->value)->toBe($grade);
})->with([
    'no term has closed' => [null, 'C'],
    'the term is still open' => ['2026-10-31', 'C'],
    'the term closed on the reference date' => ['2026-10-12', 'C'],
    'the term has closed' => ['2026-10-01', 'D'],
]);

it('counts an opt-in made on a term\'s first and last day, not the day before or after', function (string $on, string $grade) {
    $report = templateReport(function (array $document) use ($on) {
        $document['opt_in_terms'] = [['opened_on' => '2026-09-10', 'closed_on' => '2026-09-30', 'channel' => null]];
        $document['records'][4]['opt_in'] = ['on' => $on, 'channel' => 'registry', 'action' => 'action-1'];
        $document['records'][4]['parallel_declared'] = true;

        return $document;
    });

    expect($report->gradings['r-0005']->grade->value)->toBe($grade);
})->with([
    'the day before' => ['2026-09-09', 'D'],
    'the first day' => ['2026-09-10', 'C'],
    'the last day' => ['2026-09-30', 'C'],
    'the day after' => ['2026-10-01', 'D'],
]);

it('takes the channel the law names where the annex has no window, whatever a term states', function (?string $termChannel) {
    $report = templateReport(function (array $document) use ($termChannel) {
        $document['annex'] = 'at';
        $document['opt_in_terms'] = [['opened_on' => '2026-09-01', 'closed_on' => '2026-12-01', 'channel' => $termChannel]];
        $document['records'][1]['opt_in'] = ['on' => '2026-09-15', 'channel' => 'web', 'action' => 'action-1'];
        $document['records'][1]['parallel_declared'] = true;

        return $document;
    }, 'at');

    expect($report->gradings['r-0002']->grade->value)->toBe('D')
        ->and($report->gradings['r-0002']->reasonCodes())->toBe(['not_opted_in']);
})->with([
    'a term without a channel' => [null],
    'a term naming the channel used' => ['web'],
]);

it('asks a record abroad for its opt-in under Annex PT only in an action under the Directive', function (bool $directive, string $grade) {
    $report = templateReport(function (array $document) use ($directive) {
        $document['annex'] = 'pt';
        $document['action']['directive'] = $directive;

        return $document;
    }, 'pt');

    expect($report->gradings['r-0002']->grade->value)->toBe($grade);
})->with([
    'under DL 114-A/2023' => [true, 'D'],
    'under the LAP alone' => [false, 'B'],
]);

it('asks the export to state whether residents opt in where the annex leaves it to the action', function () {
    $report = Check::run(Register::read(template('register.json'), template('records.csv')), new Annex('be', 'Annex BE', 'BE', true, 'action', true, 'registry', false, []));

    expect(codes($report))->toContain('residents_unstated')
        ->and($report->level())->toBeNull();
});

it('finds opt-in terms where the action binds nobody on an opt-in', function () {
    $report = sealed(function (array $document) {
        $document['annex'] = 'pt';
        $document['action']['directive'] = false;
        $document['opt_in_terms'] = [['opened_on' => '2026-09-01', 'closed_on' => null, 'channel' => null]];

        foreach ($document['records'] as $index => $record) {
            unset($document['records'][$index]['grade']);
        }

        return $document;
    }, 'pt');

    expect(codes($report))->toBe(['opt_in_terms_without_opt_in']);
});

it('counts per part of a split claim, a record in both parts in each', function () {
    $report = sealed(function (array $document) {
        $document['parts'] = ['pre_wamca', 'wamca'];

        foreach ($document['records'] as $index => $record) {
            $document['records'][$index]['parts'] = $index === 0 ? ['pre_wamca', 'wamca'] : ['wamca'];
        }

        return $document;
    });

    expect($report->parts())->toBe([
        'pre_wamca' => ['A' => 1, 'B' => 0, 'C' => 0, 'D' => 0],
        'wamca' => ['A' => 1, 'B' => 2, 'C' => 2, 'D' => 2],
    ]);
});

it('counts a record in both parts at the grade each part gives it, the opt-in in its own part alone', function () {
    $report = sealed(function (array $document) {
        $document['parts'] = ['pre_wamca', 'wamca'];
        $document['denominator']['parts'] = ['pre_wamca' => size(100, 200), 'wamca' => size(21000, 26000)];
        $document['opt_in_terms'] = [['opened_on' => '2026-09-15', 'closed_on' => '2026-10-01', 'channel' => null]];

        foreach ($document['records'] as $index => $record) {
            $document['records'][$index]['parts'] = $index === 0 ? ['pre_wamca', 'wamca'] : ['wamca'];
        }

        $document['records'][0]['residence']['country'] = 'BE';
        unset($document['records'][4]['grade']);

        return $document;
    });

    expect($report->gradings['r-0001']->grade->value)->toBe('A')
        ->and($report->gradings['r-0005']->grade->value)->toBe('D')
        ->and(codes($report))->toBe([])
        ->and($report->parts())->toBe([
            'pre_wamca' => ['A' => 1, 'B' => 0, 'C' => 0, 'D' => 0],
            'wamca' => ['A' => 0, 'B' => 2, 'C' => 1, 'D' => 4],
        ]);
});

it('measures coverage per subgroup and per part against a denominator of its own', function () {
    $report = sealed(function (array $document) {
        $document['subgroups'] = ['retail', 'lease'];
        $document['parts'] = ['pre_wamca', 'wamca'];
        $document['denominator']['subgroups'] = ['retail' => size(10, 20), 'lease' => size(1000, 1000)];
        $document['denominator']['parts'] = ['pre_wamca' => size(100, 200), 'wamca' => size(21000, 26000)];

        foreach ($document['records'] as $index => $record) {
            $document['records'][$index]['subgroup'] = $index === 0 ? 'retail' : 'lease';
            $document['records'][$index]['parts'] = $index === 0 ? ['pre_wamca', 'wamca'] : ['wamca'];
        }

        return $document;
    });

    expect(codes($report))->toBe([])
        ->and($report->groupCoverage())->toBe([
            'subgroups' => [
                'retail' => ['A' => ['records' => 1, 'low' => 5.0, 'high' => 10.0], 'B' => ['records' => 1, 'low' => 5.0, 'high' => 10.0], 'C' => ['records' => 1, 'low' => 5.0, 'high' => 10.0]],
                'lease' => ['A' => ['records' => 0, 'low' => 0.0, 'high' => 0.0], 'B' => ['records' => 2, 'low' => 0.2, 'high' => 0.2], 'C' => ['records' => 4, 'low' => 0.4, 'high' => 0.4]],
            ],
            'parts' => [
                'pre_wamca' => ['A' => ['records' => 1, 'low' => 0.5, 'high' => 1.0], 'B' => ['records' => 1, 'low' => 0.5, 'high' => 1.0], 'C' => ['records' => 1, 'low' => 0.5, 'high' => 1.0]],
                'wamca' => ['A' => ['records' => 1, 'low' => 0.0038, 'high' => 0.0048], 'B' => ['records' => 3, 'low' => 0.0115, 'high' => 0.0143], 'C' => ['records' => 5, 'low' => 0.0192, 'high' => 0.0238]],
            ],
        ])
        ->and(Statement::draft($report, issuedOn: '2026-10-15')['subgroups']['retail'])->toBe([
            'denominator' => ['low' => 10, 'high' => 20, 'source' => 'RDW open data', 'as_of' => '2026-09-01'],
            'coverage' => $report->groupCoverage()['subgroups']['retail'],
        ])
        ->and(Statement::draft($report, issuedOn: '2026-10-15')['parts'])->toHaveKeys(['pre_wamca', 'wamca'])
        ->and(Statement::draft(templateReport(), issuedOn: '2026-10-15'))->not->toHaveKeys(['subgroups', 'parts']);
});

it('withholds every level from a split claim without the size of each part', function () {
    $report = sealed(function (array $document) {
        $document['parts'] = ['pre_wamca', 'wamca'];
        $document['denominator']['parts'] = ['wamca' => size(21000, 26000)];

        foreach ($document['records'] as $index => $record) {
            $document['records'][$index]['parts'] = ['wamca'];
        }

        return $document;
    });

    expect(codes($report))->toBe(['part_without_denominator'])
        ->and($report->level())->toBeNull();
});

it('stays at identified without the size of each subgroup', function () {
    $report = sealed(function (array $document) {
        $document['subgroups'] = ['retail', 'lease'];
        $document['denominator']['subgroups'] = ['retail' => size(10, 20)];

        foreach ($document['records'] as $index => $record) {
            $document['records'][$index]['subgroup'] = $index === 0 ? 'retail' : 'lease';
        }

        return $document;
    });

    expect(codes($report))->toBe(['subgroup_without_denominator'])
        ->and($report->level())->toBe(Level::Identified);
});

it('rejects an anchor that does not name its root or its manifest', function (string $member) {
    templateReport(function (array $document) use ($member) {
        unset($document['anchor'][$member]);

        return $document;
    });
})->throws(InvalidRegister::class)->with(['root', 'manifest']);

it('refuses to print the snapshot of an export whose ids repeat', function () {
    $path = tempnam(sys_get_temp_dir(), 'vcrs').'.json';
    $document = templateDocument();
    $document['records'][] = [...$document['records'][1], 'id' => $document['records'][0]['id']];
    file_put_contents($path, json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    exec('php '.escapeshellarg(dirname(__DIR__).'/bin/vcrs').' manifest '.escapeshellarg($path).' 2>&1', $output, $status);

    expect($status)->toBe(4)
        ->and($output[0])->toBe('The export is not consistent, so it has no one snapshot: check it first.');
});

it('proves a record against the anchored root, and against the manifest that commits to it, without the register', function () {
    $vcrs = escapeshellarg(dirname(__DIR__).'/bin/vcrs');
    $anchor = json_decode(file_get_contents(template('register.json')), true)['anchor'];
    $records = ' --records='.escapeshellarg(template('records.csv'));
    $proof = tempnam(sys_get_temp_dir(), 'vcrs').'.json';
    $manifest = tempnam(sys_get_temp_dir(), 'vcrs').'.json';
    file_put_contents($proof, shell_exec("php {$vcrs} proof ".escapeshellarg(template('register.json')).$records.' --record=r-0003'));
    file_put_contents($manifest, shell_exec("php {$vcrs} manifest ".escapeshellarg(template('register.json')).$records.' --json'));
    $line = json_decode(file_get_contents($proof), true)['line'];

    exec("php {$vcrs} verify ".escapeshellarg($proof).' --root='.escapeshellarg($anchor['root']), $held, $heldStatus);
    exec("php {$vcrs} verify ".escapeshellarg($proof).' --manifest='.escapeshellarg($manifest), $committed, $committedStatus);
    exec("php {$vcrs} verify ".escapeshellarg($proof).' --root=sha256:'.str_repeat('0', 64), $refused, $refusedStatus);

    expect($line)->toStartWith('r-0003 sha256:')
        ->and(json_decode(file_get_contents($proof), true)['manifest'])->toBe($anchor['manifest'])
        ->and($heldStatus)->toBe(0)
        ->and($held[0])->toBe("✓ '{$line}' is in the snapshot under {$anchor['root']}.")
        ->and($committedStatus)->toBe(0)
        ->and($committed[1])->toBe("  The manifest {$anchor['manifest']} commits to that root: check its anchor's timestamp against this digest.")
        ->and($refusedStatus)->toBe(5)
        ->and($refused[0])->toStartWith('✗');
});

it('exits with a code per outcome', function (Closure $change, int $status) {
    $path = tempnam(sys_get_temp_dir(), 'vcrs').'.json';
    file_put_contents($path, $change(json_encode(templateDocument(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

    exec('php '.escapeshellarg(dirname(__DIR__).'/bin/vcrs').' check '.escapeshellarg($path).' 2>&1', $output, $exit);

    expect($exit)->toBe($status);
})->with([
    'a level met' => [fn (string $json) => $json, 0],
    'not valid' => [fn (string $json) => str_replace('"annex":"nl"', '"annex":"nl","annex":"be"', $json), 3],
    'not consistent' => [fn (string $json) => str_replace('"live_from":"2026-09-01"', '"live_from":"2026-09-05"', $json), 4],
    'not verified' => [fn (string $json) => str_replace('"grade":"C"', '"grade":"B"', $json), 5],
]);

it('prints the check\'s figures as the standard writes them, a hard space between thousands and a point before the decimals', function () {
    $vcrs = escapeshellarg(dirname(__DIR__).'/bin/vcrs');

    exec("php {$vcrs} check ".escapeshellarg(template('register.json')).' --records='.escapeshellarg(template('records.csv')), $output);

    expect($output)->toContain("Denominator: 21\u{00A0}000 to 26\u{00A0}000 (RDW open data, registrations of the models concerned, times the take rate of package X in the defendant's annual report, 2026-09-01)")
        ->toContain('  A or better             1   0.0038% to 0.0048%');
});

it('reports the whole check as an array', function () {
    $report = templateReport()->toArray();

    expect($report['export_conditions_met'])->toBe('verified')
        ->and($report['consistent'])->toBeTrue()
        ->and($report['verified'])->toBeTrue()
        ->and($report['manifest'])->toStartWith('sha256:')
        ->and($report['subgroups'])->toBe([])
        ->and($report['grades']['r-0001'])->toBe(['grade' => 'A', 'reasons' => []]);
});

it('exits 3 on input that is not valid: a file that is missing, a record the register does not hold', function (string $arguments) {
    exec('php '.escapeshellarg(dirname(__DIR__).'/bin/vcrs').' '.$arguments.' 2>&1', $output, $status);

    expect($status)->toBe(3);
})->with([
    'a missing register' => ['check '.escapeshellarg(sys_get_temp_dir().'/vcrs-no-such-register.json')],
    'a missing spreadsheet' => ['check '.escapeshellarg(dirname(__DIR__, 2).'/vcrs/templates/register.json').' --records='.escapeshellarg(sys_get_temp_dir().'/vcrs-no-such.csv')],
    'a record the register does not hold' => ['proof '.escapeshellarg(dirname(__DIR__, 2).'/vcrs/templates/export.json').' --record=r-9999'],
]);

it('exits 2 on a level it does not know', function (string $level) {
    exec('php '.escapeshellarg(dirname(__DIR__).'/bin/vcrs').' statement '.escapeshellarg(dirname(__DIR__, 2).'/vcrs/templates/export.json').' '.$level.' 2>&1', $output, $status);

    expect($status)->toBe(2);
})->with(['--level=gold', '--level']);
