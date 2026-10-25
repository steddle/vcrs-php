<?php

use Steddle\Vcrs\Annex;
use Steddle\Vcrs\Canonical;
use Steddle\Vcrs\Check;
use Steddle\Vcrs\Register;
use Steddle\Vcrs\Statement;

/**
 * @return array{0: list<string>, 1: int} the command's output lines and its exit code
 */
function vcrs(string $arguments): array
{
    exec('php '.escapeshellarg(dirname(__DIR__).'/bin/vcrs').' '.$arguments.' 2>&1', $output, $status);

    return [$output, $status];
}

it('publishes the example register as one JSON export, the same as the spreadsheet route gives', function () {
    [$output, $status] = vcrs('export '.escapeshellarg(template('register.json')).' --records='.escapeshellarg(template('records.csv')));

    expect($status)->toBe(0)
        ->and(implode("\n", $output)."\n")->toBe(file_get_contents(template('export.json')));
});

it('checks every outcome expected.json states, by the spreadsheet route and by the JSON export alike', function (string $register) {
    [$output, $status] = vcrs('check '.$register.' --expect='.escapeshellarg(template('expected.json')));

    expect($status)->toBe(0)
        ->and($output[0])->toStartWith('✓ Every outcome expected.json states');
})->with([
    'the spreadsheet' => [escapeshellarg(template('register.json')).' --records='.escapeshellarg(template('records.csv'))],
    'the JSON export' => [escapeshellarg(template('export.json'))],
]);

it('fails the expectation where one outcome differs', function () {
    $expected = json_decode(file_get_contents(template('expected.json')), true);
    $expected['counts']['A'] = 2;
    $path = tempnam(sys_get_temp_dir(), 'vcrs').'.json';
    file_put_contents($path, json_encode($expected));

    [$output, $status] = vcrs('check '.escapeshellarg(template('export.json')).' --expect='.escapeshellarg($path));

    expect($status)->toBe(6)
        ->and($output[0])->toStartWith('✗ counts: expected');
});

it('publishes the manifest the example register anchors, and a proof that holds against it, and one altered that does not', function () {
    $anchor = json_decode(file_get_contents(template('register.json')), true)['anchor'];
    $manifest = json_decode(file_get_contents(template('manifest.json')));

    [, $held] = vcrs('verify '.escapeshellarg(template('proof.json')).' --manifest='.escapeshellarg(template('manifest.json')));
    [, $refused] = vcrs('verify '.escapeshellarg(template('proof-altered.json')).' --manifest='.escapeshellarg(template('manifest.json')));

    expect(Canonical::manifestDigest($manifest))->toBe($anchor['manifest'])
        ->and($manifest->records->root)->toBe($anchor['root'])
        ->and($held)->toBe(0)
        ->and($refused)->toBe(5);
});

it('publishes the draft statement the example register gives before the auditor reports', function () {
    $draft = json_decode(file_get_contents(template('statement-draft.json')), true);
    $report = Check::run(Register::read(template('register.json'), template('records.csv')), Annex::load('nl'));

    expect($draft)->toBe(Statement::draft($report, issuedOn: $draft['issued_on']))
        ->and($draft['draft'])->toBeTrue()
        ->and($draft['auditor'])->toBeNull();
});
