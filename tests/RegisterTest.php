<?php

use Steddle\Vcrs\Annex;
use Steddle\Vcrs\InvalidRegister;
use Steddle\Vcrs\Register;
use Steddle\Vcrs\Vcrs;

function registerFile(string $json): string
{
    $path = tempnam(sys_get_temp_dir(), 'vcrs').'.json';
    file_put_contents($path, $json);

    return $path;
}

it('refuses a member name that appears twice in one object, wherever it is', function (string $from, string $to, string $pointer) {
    $json = str_replace($from, $to, file_get_contents(template('register.json')));

    expect(fn () => Register::read(registerFile($json), template('records.csv')))
        ->toThrow(InvalidRegister::class, "{$pointer}: the member appears more than once in its object.");
})->with([
    'at the top' => ['"annex": "nl",', '"annex": "nl", "annex": "be",', '/annex'],
    'in an object' => ['"version": "1",', '"version": "1", "version": "2",', '/class/version'],
    'in an object in an array' => ['"id": "f1",', '"id": "f1", "id": "f2",', '/flows/0/id'],
    'under an escaped name' => ['"version": "1",', '"version": "1", "version": "2",', '/class/version'],
]);

it('names its own version and the export\'s when they differ', function () {
    $json = str_replace('"vcrs": "'.Vcrs::VERSION.'"', '"vcrs": "0.1.0-beta.3"', file_get_contents(template('register.json')));

    expect(fn () => Register::read(registerFile($json)))
        ->toThrow(InvalidRegister::class, '/vcrs: this validator implements VCRS '.Vcrs::VERSION.', and the export names "0.1.0-beta.3".');
});

it('refuses an annex the version does not hold', function () {
    Annex::load('xx');
})->throws(InvalidRegister::class, '/annex: VCRS '.Vcrs::VERSION." holds no annex 'xx'.");

it('refuses a date that is not on the calendar', function () {
    $json = str_replace('"reference_date": "2026-10-12"', '"reference_date": "2026-02-30"', file_get_contents(template('register.json')));

    expect(fn () => Register::read(registerFile($json), template('records.csv')))->toThrow(InvalidRegister::class, "/reference_date: The data must match the 'date' format");
});
