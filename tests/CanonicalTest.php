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

it('writes a number as RFC 8785 does, from the examples of its Appendix B', function (string $ieee, string $written) {
    expect(Canonical::json(unpack('E', hex2bin($ieee))[1]))->toBe($written);
})->with([
    ['0000000000000000', '0'],
    ['8000000000000000', '0'],
    ['0000000000000001', '5e-324'],
    ['8000000000000001', '-5e-324'],
    ['7fefffffffffffff', '1.7976931348623157e+308'],
    ['ffefffffffffffff', '-1.7976931348623157e+308'],
    ['4340000000000000', '9007199254740992'],
    ['c340000000000000', '-9007199254740992'],
    ['4430000000000000', '295147905179352830000'],
    ['44b52d02c7e14af5', '9.999999999999997e+22'],
    ['44b52d02c7e14af6', '1e+23'],
    ['44b52d02c7e14af7', '1.0000000000000001e+23'],
    ['444b1ae4d6e2ef4e', '999999999999999700000'],
    ['444b1ae4d6e2ef4f', '999999999999999900000'],
    ['444b1ae4d6e2ef50', '1e+21'],
    ['3eb0c6f7a0b5ed8c', '9.999999999999997e-7'],
    ['3eb0c6f7a0b5ed8d', '0.000001'],
    ['41b3de4355555553', '333333333.3333332'],
    ['41b3de4355555554', '333333333.33333325'],
    ['41b3de4355555555', '333333333.3333333'],
    ['41b3de4355555556', '333333333.3333334'],
    ['41b3de4355555557', '333333333.33333343'],
    ['becbf647612f3696', '-0.0000033333333333333333'],
    ['43143ff3c1cb0959', '1424953923781206.2'],
]);

it('writes integers and the share of a facet as RFC 8785 does', function (int|float $number, string $written) {
    expect(Canonical::json($number))->toBe($written);
})->with([
    [21000, '21000'],
    [0, '0'],
    [-1, '-1'],
    [0.45, '0.45'],
    [1.0, '1'],
    [1e-5, '0.00001'],
    [1e-7, '1e-7'],
    [1e20, '100000000000000000000'],
    [9007199254740993, '9007199254740992'],
]);

it('refuses a number RFC 8785 has no form for', function (float $number) {
    Canonical::json($number);
})->throws(InvalidArgumentException::class)->with([NAN, INF, -INF]);

it('writes strings and sorts members as RFC 8785 does', function () {
    $value = (object) ["\u{FF5E}" => 1, "\u{1F600}" => 2, 'a' => "\u{0000}\u{0007}\u{0008}\t\n\u{000B}\f\r\u{001F}\"\\/\u{007F}\u{2028}é€😀", 'b' => [true, false, null]];

    expect(Canonical::json($value))->toBe('{"a":"\u0000\u0007\b\t\n\u000b\f\r\u001f\"\\\\/'."\u{007F}\u{2028}é€😀".'","b":[true,false,null],"'."\u{1F600}".'":2,"'."\u{FF5E}".'":1}');
});

it('gives the canonical form of RFC 8785, Appendix E', function () {
    expect(Canonical::json(json_decode('{"time": "2019-01-28T07:45:10Z", "big": "055", "val": 3.5}')))->toBe('{"big":"055","time":"2019-01-28T07:45:10Z","val":3.5}');
});

it('builds the manifest from the export without its anchor, its records as their number and root', function () {
    $export = json_decode('{"vcrs": "x", "anchor": {"root": "sha256:a"}, "records": [{"id": "r-1"}, {"id": "r-2"}], "denominator": {"low": 1, "high": 2}}');
    $manifest = Canonical::manifest($export, 'sha256:root');

    expect(Canonical::json($manifest))->toBe('{"denominator":{"high":2,"low":1},"records":{"count":2,"root":"sha256:root"},"vcrs":"x"}')
        ->and(Canonical::manifestDigest($manifest))->toBe('sha256:'.hash('sha256', Canonical::json($manifest)))
        ->and(count($export->records))->toBe(2)
        ->and(isset($export->anchor))->toBeTrue();
});
