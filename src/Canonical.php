<?php

namespace Steddle\Vcrs;

use InvalidArgumentException;
use stdClass;
use Steddle\Seal\Canonical as Seal;
use Steddle\Seal\Fingerprint;
use Steddle\Seal\Refused;

/**
 * The canonical form of section 11.3, RFC 8785, and the snapshot of section 5.4: the Merkle Tree of RFC 9162,
 * section 2.1, over one line per record, and the manifest that binds the export's context to its root.
 */
final class Canonical
{
    /**
     * @throws Refused
     */
    public static function json(mixed $value): string
    {
        return Seal::json($value);
    }

    /**
     * The record's fingerprint: its canonical form without its own fingerprint member.
     */
    public static function fingerprint(stdClass $record): string
    {
        $record = clone $record;
        unset($record->fingerprint);

        return (string) Fingerprint::of(self::json($record));
    }

    /**
     * The manifest of section 5.4: the export without its anchor, its records replaced by their number and the
     * snapshot root, so one fingerprint commits to the records and to the context that grades them.
     */
    public static function manifest(stdClass $export, string $root): stdClass
    {
        $manifest = clone $export;
        unset($manifest->anchor);
        $manifest->records = (object) ['count' => count($export->records), 'root' => $root];

        return $manifest;
    }

    public static function manifestFingerprint(stdClass $manifest): string
    {
        return (string) Fingerprint::of(self::json($manifest));
    }

    /**
     * @param  array<string, string>  $fingerprints  per record id
     * @return list<string> `{id} {fingerprint}`, ordered by identifier in byte order
     */
    public static function lines(array $fingerprints): array
    {
        ksort($fingerprints, SORT_STRING);

        $lines = [];

        foreach ($fingerprints as $id => $fingerprint) {
            $lines[] = "{$id} {$fingerprint}";
        }

        return $lines;
    }

    /**
     * The Merkle Tree Hash of RFC 9162, section 2.1.1, over the lines.
     *
     * @param  array<string, string>  $fingerprints  per record id
     */
    public static function root(array $fingerprints): string
    {
        $leaves = array_map(self::leaf(...), self::lines($fingerprints));

        return self::written(self::tree($leaves, 0, count($leaves)));
    }

    /**
     * A record's line with its inclusion proof of RFC 9162, section 2.1.3.1: its index among the lines, their number,
     * and the nodes from its leaf up.
     *
     * @param  array<string, string>  $fingerprints  per record id
     * @return array{line: string, index: int, size: int, proof: list<string>, root: string}
     */
    public static function proof(array $fingerprints, string $id): array
    {
        if (! isset($fingerprints[$id])) {
            throw new InvalidArgumentException("The register holds no record {$id}.");
        }

        $lines = self::lines($fingerprints);
        $leaves = array_map(self::leaf(...), $lines);
        $index = array_search("{$id} {$fingerprints[$id]}", $lines, true);

        return [
            'line' => $lines[$index],
            'index' => $index,
            'size' => count($leaves),
            'proof' => array_map(self::written(...), self::path($leaves, $index, 0, count($leaves))),
            'root' => self::written(self::tree($leaves, 0, count($leaves))),
        ];
    }

    /**
     * Whether the line is in the snapshot under `$root`, checked from its index, the number of lines and its proof
     * alone, as RFC 9162, section 2.1.3.2 sets out.
     *
     * @param  list<mixed>  $proof
     */
    public static function includes(string $root, string $line, int $index, int $size, array $proof): bool
    {
        if ($index < 0 || $index >= $size) {
            return false;
        }

        $hash = self::leaf($line);
        $fn = $index;
        $sn = $size - 1;

        foreach ($proof as $node) {
            if ($sn === 0 || ! is_string($node) || ! preg_match('/^sha256:[0-9a-f]{64}$/', $node)) {
                return false;
            }

            $node = hex2bin(substr($node, 7));

            if ($fn % 2 === 1 || $fn === $sn) {
                $hash = self::node($node, $hash);

                while ($fn % 2 === 0 && $fn !== 0) {
                    $fn >>= 1;
                    $sn >>= 1;
                }
            } else {
                $hash = self::node($hash, $node);
            }

            $fn >>= 1;
            $sn >>= 1;
        }

        return $sn === 0 && hash_equals(self::written($hash), $root);
    }

    /**
     * MTH(D[from:to]) over the leaves' hashes.
     *
     * @param  list<string>  $leaves
     */
    private static function tree(array $leaves, int $from, int $to): string
    {
        if ($to === $from) {
            return hash('sha256', '', true);
        }

        if ($to - $from === 1) {
            return $leaves[$from];
        }

        $split = $from + self::split($to - $from);

        return self::node(self::tree($leaves, $from, $split), self::tree($leaves, $split, $to));
    }

    /**
     * PATH(m, D[from:to]), `$m` counted from `$from`.
     *
     * @param  list<string>  $leaves
     * @return list<string>
     */
    private static function path(array $leaves, int $m, int $from, int $to): array
    {
        if ($to - $from === 1) {
            return [];
        }

        $k = self::split($to - $from);

        return $m < $k
            ? [...self::path($leaves, $m, $from, $from + $k), self::tree($leaves, $from + $k, $to)]
            : [...self::path($leaves, $m - $k, $from + $k, $to), self::tree($leaves, $from, $from + $k)];
    }

    /**
     * The largest power of two smaller than `$n`, for `$n` above 1.
     */
    private static function split(int $n): int
    {
        $k = 1;

        while ($k * 2 < $n) {
            $k *= 2;
        }

        return $k;
    }

    private static function leaf(string $line): string
    {
        return hash('sha256', "\x00".$line, true);
    }

    private static function node(string $left, string $right): string
    {
        return hash('sha256', "\x01".$left.$right, true);
    }

    private static function written(string $hash): string
    {
        return 'sha256:'.bin2hex($hash);
    }
}
