<?php

use Steddle\Vcrs\Annex;
use Steddle\Vcrs\Grader;
use Steddle\Vcrs\Schema;
use Steddle\Vcrs\Window;

it('grades every vector as it states', function (string $path) {
    $vector = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $context = $vector['context'] ?? ['reference_date' => null, 'opt_in_closed_on' => null];
    $annex = ($vector['annex'] === null ? Annex::core() : Annex::load($vector['annex']))->forAction($context['directive'] ?? false);

    $grading = (new Grader($vector['flows'], $annex, $annex->optInDue($context['opt_in_closed_on'], $context['reference_date']), Window::from($context)))->grade($vector['record']);

    expect($grading->grade->value)->toBe($vector['expected']['grade'])
        ->and($grading->reasonCodes())->toBe($vector['expected']['reasons']);
})->with(vectors());

it('holds only records the record schema accepts', function (string $path) {
    $vector = json_decode(file_get_contents($path), flags: JSON_THROW_ON_ERROR);

    expect(Schema::errors($vector->record, 'record'))->toBe([]);
})->with(vectors());
