<?php

use Steddle\Vcrs\Reason;

/**
 * @return array<string, array{grade: string, rule: string}> code => its row in core 11.5, in the table's order
 */
function reasonTable(): array
{
    preg_match('/^### 11\.5 Reasons\n(.*?)^##? /ms', file_get_contents(standardFile('core.md')), $section);
    preg_match_all('/^\| `([a-z_]+)` \| ([A-D]) \| ([^|]+) \|$/m', $section[1], $rows, PREG_SET_ORDER);

    return collect($rows)->mapWithKeys(fn (array $row): array => [$row[1] => ['grade' => $row[2], 'rule' => trim($row[3])]])->all();
}

it('defines in core 11.5 every reason the validator reports, in the enum\'s order', function () {
    expect(array_keys(reasonTable()))->toBe(array_column(Reason::cases(), 'value'));
});

it('ties each reason to the rule of the grade it holds a record at', function () {
    foreach (reasonTable() as $code => ['grade' => $grade, 'rule' => $rule]) {
        expect($rule)->toMatch(['D' => '/^4\.1, rule [1-8]$/', 'C' => '/^4\.3$/', 'B' => '/^4\.4$/'][$grade], "{$code} cites {$rule} at grade {$grade}.");
    }
});

it('orders the table by its rules: 4.1 rule 1 to 8, then 4.3, then 4.4', function () {
    $rules = array_values(array_map(fn (array $row): string => $row['rule'], reasonTable()));
    $sorted = $rules;
    usort($sorted, fn (string $a, string $b): int => strnatcmp(str_replace('4.1, rule ', '4.1.', $a), str_replace('4.1, rule ', '4.1.', $b)));

    expect($rules)->toBe($sorted);
});

it('gives a vector the grade core 11.5 sets for every reason it reports, in the table\'s order, and every reason a vector', function () {
    $table = reasonTable();
    $order = array_flip(array_keys($table));
    $seen = [];

    foreach (vectors() as $name => [$path]) {
        $expected = json_decode(file_get_contents($path), true)['expected'];

        foreach ($expected['reasons'] as $code) {
            expect($table[$code]['grade'] ?? null)->toBe($expected['grade'], "{$name} reports {$code} at grade {$expected['grade']}.");
            $seen[$code] = true;
        }

        $ranks = array_map(fn (string $code): int => $order[$code], $expected['reasons']);
        expect($ranks)->toBe(collect($ranks)->sort()->values()->all(), "{$name} lists its reasons out of the table's order.");
    }

    expect(array_keys($table))->toEqualCanonicalizing(array_keys($seen));
});
