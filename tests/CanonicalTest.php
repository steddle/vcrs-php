<?php

use Steddle\Vcrs\Canonical;

/**
 * @return array<string, string> `$size` records, r-1 onwards, each with a digest of its own
 */
function snapshot(int $size): array
{
    $digests = [];

    for ($n = 1; $n <= $size; $n++) {
        $digests["r-{$n}"] = 'sha256:'.hash('sha256', "record {$n}");
    }

    return $digests;
}

/**
 * The leaf hash of RFC 9162, section 2.1.1, of the `$n`th record of `snapshot()`.
 */
function leafOf(int $n): string
{
    return hash('sha256', "\x00r-{$n} sha256:".hash('sha256', "record {$n}"), true);
}

function nodeOf(string $left, string $right): string
{
    return hash('sha256', "\x01".$left.$right, true);
}

it('orders the lines by identifier in byte order', function () {
    $lines = Canonical::lines(['b' => 'sha256:b', 'a' => 'sha256:a', '9' => 'sha256:9', '10' => 'sha256:10']);

    expect($lines)->toBe(['10 sha256:10', '9 sha256:9', 'a sha256:a', 'b sha256:b']);
});

it('gives the Merkle Tree Hash of RFC 9162 as the root', function (int $size, Closure $tree) {
    expect(Canonical::root(snapshot($size)))->toBe('sha256:'.bin2hex($tree()));
})->with([
    'no record' => [0, fn () => hash('sha256', '', true)],
    'one record' => [1, fn () => leafOf(1)],
    'two records' => [2, fn () => nodeOf(leafOf(1), leafOf(2))],
    'three records' => [3, fn () => nodeOf(nodeOf(leafOf(1), leafOf(2)), leafOf(3))],
    'five records' => [5, fn () => nodeOf(nodeOf(nodeOf(leafOf(1), leafOf(2)), nodeOf(leafOf(3), leafOf(4))), leafOf(5))],
]);

it('gives the inclusion proofs of the seven-leaf example in RFC 9162, section 2.1.5', function (string $id, Closure $nodes) {
    $proof = Canonical::proof(snapshot(7), $id);

    expect($proof['proof'])->toBe(array_map(fn (string $node): string => 'sha256:'.bin2hex($node), $nodes()));
})->with([
    'd0: b, h, l' => ['r-1', fn () => [leafOf(2), nodeOf(leafOf(3), leafOf(4)), nodeOf(nodeOf(leafOf(5), leafOf(6)), leafOf(7))]],
    'd3: c, g, l' => ['r-4', fn () => [leafOf(3), nodeOf(leafOf(1), leafOf(2)), nodeOf(nodeOf(leafOf(5), leafOf(6)), leafOf(7))]],
    'd4: f, j, k' => ['r-5', fn () => [leafOf(6), leafOf(7), nodeOf(nodeOf(leafOf(1), leafOf(2)), nodeOf(leafOf(3), leafOf(4)))]],
    'd6: i, k' => ['r-7', fn () => [nodeOf(leafOf(5), leafOf(6)), nodeOf(nodeOf(leafOf(1), leafOf(2)), nodeOf(leafOf(3), leafOf(4)))]],
]);

it('proves every record of a snapshot from its line and proof alone', function (int $size) {
    $digests = snapshot($size);
    $root = Canonical::root($digests);

    foreach (array_keys($digests) as $id) {
        $proof = Canonical::proof($digests, $id);

        expect($proof['line'])->toBe("{$id} {$digests[$id]}")
            ->and($proof['size'])->toBe($size)
            ->and($proof['root'])->toBe($root)
            ->and(Canonical::includes($root, $proof['line'], $proof['index'], $proof['size'], $proof['proof']))->toBeTrue();
    }
})->with([1, 2, 3, 5, 7, 8, 13]);

it('rejects a proof that does not place the line under the root', function (Closure $change) {
    $proof = Canonical::proof(snapshot(7), 'r-5');
    ['root' => $root, 'line' => $line, 'index' => $index, 'size' => $size, 'proof' => $nodes] = $change($proof);

    expect(Canonical::includes($root, $line, $index, $size, $nodes))->toBeFalse();
})->with([
    'a changed line' => [fn (array $proof) => [...$proof, 'line' => str_replace('r-5', 'r-6', $proof['line'])]],
    'another index' => [fn (array $proof) => [...$proof, 'index' => 5]],
    'an index past the end' => [fn (array $proof) => [...$proof, 'index' => 7]],
    'a smaller size' => [fn (array $proof) => [...$proof, 'size' => 6]],
    'another root' => [fn (array $proof) => [...$proof, 'root' => Canonical::root(snapshot(6))]],
    'a node short' => [fn (array $proof) => [...$proof, 'proof' => array_slice($proof['proof'], 0, -1)]],
    'a node too many' => [fn (array $proof) => [...$proof, 'proof' => [...$proof['proof'], $proof['proof'][0]]]],
    'a node not written as a digest' => [fn (array $proof) => [...$proof, 'proof' => ['sha256:xyz', ...array_slice($proof['proof'], 1)]]],
]);

it('refuses a proof for a record the register does not hold', function () {
    Canonical::proof(snapshot(3), 'r-4');
})->throws(InvalidArgumentException::class, 'The register holds no record r-4.');
