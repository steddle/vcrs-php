<?php

use Steddle\Vcrs\Vcrs;

it('names the version the validator implements wherever the standard names a version or its address', function () {
    $named = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(standardFile(''), FilesystemIterator::SKIP_DOTS)) as $file) {
        preg_match_all('#(?:VCRS |vcrs\.org/v/)(\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?)#', file_get_contents($file->getPathname()), $versions);

        if ($versions[1] !== []) {
            $named[substr($file->getPathname(), strlen(standardFile('')))] = array_unique($versions[1]);
        }
    }

    expect(array_keys($named))->toContain('core.md', 'README.md', 'annexes/_template.md', 'annexes/nl.md', 'annexes/pt.md', 'templates/conformance-statement.md', 'schema/export.schema.json', 'schema/record.schema.json', 'schema/conformance.schema.json');

    foreach ($named as $path => $versions) {
        expect(array_values($versions))->toBe([Vcrs::VERSION], "{$path} names another version than the validator's.");
    }
});

it('gives every schema the address the validator resolves it at', function (string $schema) {
    expect(json_decode(file_get_contents(standardFile("schema/{$schema}.schema.json")), true)['$id'])
        ->toBe('https://vcrs.org/schema/'.Vcrs::VERSION."/{$schema}.schema.json");
})->with(['export', 'record', 'conformance']);

it('requires the version in an export and a statement, and the example register states it', function () {
    expect(json_decode(file_get_contents(standardFile('schema/export.schema.json')), true)['properties']['vcrs']['const'])->toBe(Vcrs::VERSION)
        ->and(json_decode(file_get_contents(standardFile('schema/conformance.schema.json')), true)['properties']['vcrs']['const'])->toBe(Vcrs::VERSION)
        ->and(json_decode(file_get_contents(template('register.json')), true)['vcrs'])->toBe(Vcrs::VERSION);
});
