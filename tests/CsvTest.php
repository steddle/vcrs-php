<?php

use Steddle\Vcrs\Annex;
use Steddle\Vcrs\Check;
use Steddle\Vcrs\InvalidRegister;
use Steddle\Vcrs\Register;

function templateCsv(callable $change): string
{
    $path = tempnam(sys_get_temp_dir(), 'vcrs').'.csv';
    file_put_contents($path, $change(file_get_contents(template('records.csv'))));

    return $path;
}

it('reads the yes and no a Dutch spreadsheet writes', function () {
    $csv = templateCsv(fn (string $text) => str_replace('r-0001,natural,active,2026-09-01,f1,t1,yes,yes,,yes,', 'r-0001,natural,active,2026-09-01,f1,t1,WAAR,WAAR,,WAAR,', $text));
    $report = Check::run(Register::read(template('register.json'), $csv), Annex::load('nl'));

    expect($report->gradings['r-0001']->grade->value)->toBe('A');
});

it('refuses a yes or no it does not know', function () {
    $csv = templateCsv(fn (string $text) => str_replace('r-0001,natural,active,2026-09-01,f1,t1,yes,', 'r-0001,natural,active,2026-09-01,f1,t1,x,', $text));

    Register::read(template('register.json'), $csv);
})->throws(InvalidRegister::class, "/records/0: name holds 'x', which is neither yes nor no.");

it('reads a csv saved with a byte order mark', function () {
    $csv = templateCsv(fn (string $text) => "\u{FEFF}".$text);

    expect(Check::run(Register::read(template('register.json'), $csv), Annex::load('nl'))->root)
        ->toBe('sha256:2f4447806f0aeddb4f7768fe40c6b34aa55fb950d02385e5316da2bc4af420b5');
});

it('refuses a csv separated by semicolons', function () {
    $csv = templateCsv(fn (string $text) => str_replace(',', ';', $text));

    Register::read(template('register.json'), $csv);
})->throws(InvalidRegister::class, 'Columns are separated by commas.');

it('refuses a csv that is not utf-8', function () {
    $csv = templateCsv(fn (string $text) => mb_convert_encoding(str_replace('RDW kentekenregister', 'RDW kentekenregister één', $text), 'Windows-1252', 'UTF-8'));

    Register::read(template('register.json'), $csv);
})->throws(InvalidRegister::class, '/records/0: the row is not UTF-8. Save the CSV as CSV UTF-8.');

it('refuses a csv header that is not utf-8', function () {
    $csv = templateCsv(function (string $text) {
        [$header, $rows] = explode("\n", $text, 2);

        return mb_convert_encoding(str_replace('facet.region', 'facet.região', $header), 'Windows-1252', 'UTF-8')."\n".$rows;
    });

    Register::read(template('register.json'), $csv);
})->throws(InvalidRegister::class, '/records: the header is not UTF-8. Save the CSV as CSV UTF-8.');

it('refuses a register file that is not json', function () {
    $path = tempnam(sys_get_temp_dir(), 'vcrs').'.json';
    file_put_contents($path, substr(file_get_contents(template('register.json')), 0, 200));

    Register::read($path);
})->throws(InvalidRegister::class, '/: the file is not JSON');

it('refuses a register file that holds no json object', function () {
    $path = tempnam(sys_get_temp_dir(), 'vcrs').'.json';
    file_put_contents($path, '[]');

    Register::read($path);
})->throws(InvalidRegister::class, '/: the file holds no JSON object.');

it('refuses a row with more fields than the header', function () {
    $csv = templateCsv(fn (string $text) => preg_replace('/^(r-0002,.*)$/m', '$1,extra', $text));

    Register::read(template('register.json'), $csv);
})->throws(InvalidRegister::class, '/records/1: the row has more fields than the header.');
