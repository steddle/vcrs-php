<?php

use Steddle\Vcrs\Annex;
use Steddle\Vcrs\Check;
use Steddle\Vcrs\Csv;
use Steddle\Vcrs\InvalidRegister;
use Steddle\Vcrs\Level;
use Steddle\Vcrs\Register;
use Steddle\Vcrs\Report;
use Steddle\Vcrs\Statement;
use Steddle\Vcrs\Vcrs;

function templateReport(?callable $change = null): Report
{
    $path = template('register.json');

    if ($change !== null) {
        $document = json_decode(file_get_contents($path), true);
        $document['records'] = array_map(fn ($record) => json_decode(json_encode($record), true), Csv::records(template('records.csv')));
        $document = $change($document);
        $path = tempnam(sys_get_temp_dir(), 'vcrs').'.json';
        file_put_contents($path, json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return Check::run(Register::read($path), Annex::load('nl'));
    }

    return Check::run(Register::read($path, template('records.csv')), Annex::load('nl'));
}

it('checks the template register at level verified', function () {
    $report = templateReport();

    expect($report->counts())->toBe(['A' => 1, 'B' => 2, 'C' => 2, 'D' => 2, 'inactive' => 1])
        ->and($report->coverage()['C'])->toBe(['records' => 5, 'low' => round(5 / 26000 * 100, 4), 'high' => round(5 / 21000 * 100, 4)])
        ->and($report->root)->toBe('sha256:bd16f0dd9c9205fe559998e18a1424442e40f79e0d2f2696c5e9ce986da80cf2')
        ->and($report->findings)->toBe([])
        ->and($report->level())->toBe(Level::Verified);
});

it('gives a csv register the same root as the same records in json', function () {
    expect(templateReport(fn ($document) => $document)->root)->toBe(templateReport()->root);
});

it('reports the spread of counted records over the class facets', function () {
    expect(templateReport()->crossSection()['region'])->toBe([
        'noord' => ['records' => 2, 'share' => 0.4, 'class' => 0.3],
        'midden' => ['records' => 1, 'share' => 0.2, 'class' => 0.45],
        'zuid' => ['records' => 2, 'share' => 0.4, 'class' => 0.25],
    ]);
});

it('finds duplicates nobody marked', function () {
    $report = templateReport(function ($document) {
        $document['records'][5]['duplicate_of'] = null;
        unset($document['records'][5]['grade'], $document['records'][5]['digest']);

        return $document;
    });

    expect($report->findings['register_consistent'])->toContain('Records r-0004, r-0006 share a deduplication key and none is marked a duplicate.')
        ->and($report->level())->toBeNull();
});

it('catches a stated grade the facts do not give', function () {
    $report = templateReport(function ($document) {
        $document['records'][3]['grade'] = 'B';

        return $document;
    });

    expect($report->findings['register_consistent'])->toBe(['Record r-0004 states grade B; its facts give C.']);
});

it('meets no level once a record changed after its snapshot', function () {
    $report = templateReport(function ($document) {
        $document['records'][2]['joined_on'] = '2026-09-03';

        return $document;
    });

    expect($report->findings['record_digests'])->toHaveCount(1)
        ->and($report->findings['snapshot_anchored'])->toHaveCount(1)
        ->and($report->level())->toBeNull();
});

it('stays at identified without a witnessed flow', function () {
    $report = templateReport(function ($document) {
        $document['flows'][0]['capture']['witnessed'] = null;

        return $document;
    });

    expect($report->level())->toBe(Level::Identified)
        ->and($report->blocking())->toBe(['flows_witnessed' => ['Flow version f1 was not witnessed by a bailiff or notary.']]);
});

it('stays at registered without facets', function () {
    $report = templateReport(function ($document) {
        unset($document['facets']);

        return $document;
    });

    expect($report->level())->toBe(Level::Registered);
});

it('drafts a conformance statement its schema accepts', function () {
    $statement = Statement::draft(
        templateReport(),
        auditor: ['firm' => 'Voorbeeld Accountants', 'standard' => 'Standaard 4400N', 'report_date' => '2026-10-14'],
        issuedOn: '2026-10-15',
    );

    expect($statement['level'])->toBe('verified')
        ->and($statement['auditor']['check'])->toBe('reproduction_test')
        ->and($statement['snapshot']['records'])->toBe(8);
});

it('refuses a statement above the level the register meets', function () {
    $report = templateReport(function ($document) {
        $document['flows'][0]['capture']['witnessed'] = null;

        return $document;
    });

    Statement::draft($report, Level::Verified);
})->throws(InvalidArgumentException::class, 'The register meets identified, not verified.');

it('rejects an export that does not match the schema', function () {
    $path = tempnam(sys_get_temp_dir(), 'vcrs').'.json';
    file_put_contents($path, json_encode(['vcrs' => Vcrs::VERSION]));

    Register::read($path);
})->throws(InvalidRegister::class);

it('counts a record abroad without an opt-in until the window closes', function (?string $closedOn, string $grade) {
    $report = templateReport(function (array $document) use ($closedOn) {
        $document['opt_in_closed_on'] = $closedOn;
        unset($document['records'][4]['opt_in']);

        return $document;
    });

    expect($report->gradings['r-0005']->grade->value)->toBe($grade);
})->with([
    'no window has closed' => [null, 'C'],
    'the window is still open' => ['2026-10-31', 'C'],
    'the window has closed' => ['2026-10-01', 'D'],
]);

it('meets no level when the opt-in window has no opening day on or before its closing day', function (?string $openedOn) {
    $report = templateReport(function (array $document) use ($openedOn) {
        $document['opt_in_opened_on'] = $openedOn;
        $document['opt_in_closed_on'] = '2026-10-01';

        return $document;
    });

    expect($report->findings['register_consistent'])->toContain('The opt-in window closed on 2026-10-01 but states no opening day on or before it.')
        ->and($report->level())->toBeNull();
})->with([
    'no opening day' => [null],
    'an opening day after the closing day' => ['2026-10-02'],
]);

it('rejects an anchor that does not name its root', function () {
    templateReport(function (array $document) {
        unset($document['anchor']['root']);

        return $document;
    });
})->throws(InvalidRegister::class);

it('gives manifest the root check gives when an id repeats', function () {
    $path = tempnam(sys_get_temp_dir(), 'vcrs').'.json';
    $document = json_decode(file_get_contents(template('register.json')), true);
    $document['records'] = array_map(fn ($record) => json_decode(json_encode($record), true), Csv::records(template('records.csv')));
    $document['records'][] = [...$document['records'][1], 'id' => $document['records'][0]['id']];
    file_put_contents($path, json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $vcrs = escapeshellarg(dirname(__DIR__).'/bin/vcrs');

    $manifest = shell_exec("php {$vcrs} manifest ".escapeshellarg($path));
    $check = json_decode(shell_exec("php {$vcrs} check --json ".escapeshellarg($path)), true);

    expect($manifest)->toEndWith("root {$check['snapshot_root']}\n");
});

it('proves a record against the anchored root without the register', function () {
    $vcrs = escapeshellarg(dirname(__DIR__).'/bin/vcrs');
    $anchored = json_decode(file_get_contents(template('register.json')), true)['anchor']['root'];
    $path = tempnam(sys_get_temp_dir(), 'vcrs').'.json';
    file_put_contents($path, shell_exec("php {$vcrs} proof ".escapeshellarg(template('register.json')).' --records='.escapeshellarg(template('records.csv')).' --record=r-0003'));
    $line = json_decode(file_get_contents($path), true)['line'];

    exec("php {$vcrs} verify ".escapeshellarg($path).' --root='.escapeshellarg($anchored), $held, $heldStatus);
    exec("php {$vcrs} verify ".escapeshellarg($path).' --root=sha256:'.str_repeat('0', 64), $refused, $refusedStatus);

    expect($line)->toStartWith('r-0003 sha256:')
        ->and($heldStatus)->toBe(0)
        ->and($held[0])->toBe("✓ '{$line}' is in the snapshot under {$anchored}.")
        ->and($refusedStatus)->toBe(1)
        ->and($refused[0])->toStartWith('✗');
});

it('prints the check\'s figures as the standard writes them, a hard space between thousands and a point before the decimals', function () {
    $vcrs = escapeshellarg(dirname(__DIR__).'/bin/vcrs');

    exec("php {$vcrs} check ".escapeshellarg(template('register.json')).' --records='.escapeshellarg(template('records.csv')), $output);

    expect($output)->toContain("Denominator: 21\u{00A0}000 to 26\u{00A0}000 (RDW open data, registrations of the models concerned, times the take rate of package X in the defendant's annual report, 2026-09-01)")
        ->toContain('  A or better             1   0.004% to 0.005%');
});

it('reports the whole check as an array', function () {
    $report = templateReport()->toArray();

    expect($report['export_conditions_met'])->toBe('verified')
        ->and($report['subgroups'])->toBe([])
        ->and($report['grades']['r-0001'])->toBe(['grade' => 'A', 'reasons' => []]);
});
