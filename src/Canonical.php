<?php

namespace Steddle\Vcrs;

use InvalidArgumentException;
use stdClass;

/**
 * The canonical form of section 11.3: RFC 8785 for JSON without floating-point numbers,
 * which is keys sorted, no insignificant whitespace, UTF-8. And the snapshot of section 5.4:
 * the Merkle Tree of RFC 9162, section 2.1, over one line per record.
 */
final class Canonical
{
    public static function json(mixed $value): string
    {
        return json_encode(
            self::sorted($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The record's digest: its canonical form without its own digest member.
     */
    public static function digest(stdClass $record): string
    {
        $record = clone $record;
        unset($record->digest);

        return 'sha256:'.hash('sha256', self::json($record));
    }

    /**
     * @param  array<string, string>  $digests  per record id
     * @return list<string> `{id} {digest}`, ordered by identifier in byte order
     */
    public static function lines(array $digests): array
    {
        ksort($digests, SORT_STRING);

        $lines = [];

        foreach ($digests as $id => $digest) {
            $lines[] = "{$id} {$digest}";
        }

        return $lines;
    }

    /**
     * The Merkle Tree Hash of RFC 9162, section 2.1.1, over the lines.
     *
     * @param  array<string, string>  $digests  per record id
     */
    public static function root(array $digests): string
    {
        $leaves = array_map(self::leaf(...), self::lines($digests));

        return self::written(self::tree($leaves, 0, count($leaves)));
    }

    /**
     * A record's line with its inclusion proof of RFC 9162, section 2.1.3.1: its index among the lines, their number,
     * and the nodes from its leaf up.
     *
     * @param  array<string, string>  $digests  per record id
     * @return array{line: string, index: int, size: int, proof: list<string>, root: string}
     */
    public static function proof(array $digests, string $id): array
    {
        if (! isset($digests[$id])) {
            throw new InvalidArgumentException("The register holds no record {$id}.");
        }

        $lines = self::lines($digests);
        $leaves = array_map(self::leaf(...), $lines);
        $index = array_search("{$id} {$digests[$id]}", $lines, true);

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

    private static function sorted(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            uksort($properties, fn (string $a, string $b) => strcmp(self::utf16($a), self::utf16($b)));

            return (object) array_map(self::sorted(...), $properties);
        }

        if (is_array($value)) {
            return array_map(self::sorted(...), $value);
        }

        return $value;
    }

    /**
     * RFC 8785 sorts member names by their UTF-16 code units.
     */
    private static function utf16(string $name): string
    {
        return mb_convert_encoding($name, 'UTF-16BE', 'UTF-8');
    }
}
