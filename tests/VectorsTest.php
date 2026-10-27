<?php

use Steddle\Vcrs\Annex;
use Steddle\Vcrs\Canonical;
use Steddle\Vcrs\Context;
use Steddle\Vcrs\Grader;
use Steddle\Vcrs\Schema;

it('grades every vector as it states', function (string $path) {
    $vector = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $context = $vector['context'] ?? [];
    $annex = $vector['annex'] === null ? Annex::core() : Annex::load($vector['annex']);

    $grader = new Grader(new Context(
        $annex->forAction($context['directive'] ?? false),
        $context['reference_date'] ?? null,
        $vector['flows'],
        $context['opt_in_terms'] ?? [],
        $context['opt_in_residents'] ?? null,
        $context['residence_on'] ?? null,
        $context['parts'] ?? null,
        $context['court_reference'] ?? null,
    ));
    $grading = $grader->grade($vector['record']);

    expect($grading->grade->value)->toBe($vector['expected']['grade'])
        ->and($grading->reasonCodes())->toBe($vector['expected']['reasons']);

    foreach ($vector['expected']['parts'] ?? [] as $part => $expected) {
        $inPart = $grader->grade($vector['record'], $part);

        expect([$part, $inPart->grade->value, $inPart->reasonCodes()])->toBe([$part, $expected['grade'], $expected['reasons']]);
    }
})->with(vectors());

it('states the digest of every vector\'s record', function (string $path) {
    $vector = json_decode(file_get_contents($path), flags: JSON_THROW_ON_ERROR);

    expect($vector->expected->digest)->toBe(Canonical::digest($vector->record));
})->with(vectors());

it('holds only records the record schema accepts', function (string $path) {
    $vector = json_decode(file_get_contents($path), flags: JSON_THROW_ON_ERROR);

    expect(Schema::errors($vector->record, 'record'))->toBe([]);
})->with(vectors());
