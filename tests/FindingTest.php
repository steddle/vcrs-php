<?php

use Steddle\Vcrs\Finding;
use Steddle\Vcrs\Level;

/**
 * @return array<string, array{outcome: string, what: string}> code => its row in core 11.6, in the table's order
 */
function findingTable(): array
{
    preg_match('/^### 11\.6 Findings\n(.*?)^##? /ms', file_get_contents(standardFile('core.md')), $section);
    preg_match_all('/^\| `([a-z_]+)` \| ([a-z ]+) \| ([^|]+) \|$/m', $section[1], $rows, PREG_SET_ORDER);

    return collect($rows)->mapWithKeys(fn (array $row): array => [$row[1] => ['outcome' => $row[2], 'what' => trim($row[3])]])->all();
}

it('defines in core 11.6 every finding the validator reports, in the enum\'s order', function () {
    expect(array_keys(findingTable()))->toBe(array_column(Finding::cases(), 'value'));
});

it('gives each finding the outcome core 11.6 states for it: not consistent, not verified, or the level it withholds', function () {
    foreach (Finding::cases() as $finding) {
        $level = collect(Level::cases())->first(fn (Level $level): bool => in_array($finding->condition(), $level->conditions(), true));
        $expected = match ($finding->outcome()) {
            'consistent' => 'not consistent',
            'verified' => 'not verified',
            default => $level->value,
        };

        expect(findingTable()[$finding->value]['outcome'])->toBe($expected, "core 11.6 gives {$finding->value} another outcome.");
    }
});
