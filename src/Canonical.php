<?php

namespace Steddle\Vcrs;

use stdClass;

/**
 * The canonical form of section 11.3: RFC 8785 for JSON without floating-point numbers,
 * which is keys sorted, no insignificant whitespace, UTF-8.
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
     */
    public static function root(array $digests): string
    {
        ksort($digests, SORT_STRING);

        $lines = '';

        foreach ($digests as $id => $digest) {
            $lines .= "{$id} {$digest}\n";
        }

        return 'sha256:'.hash('sha256', $lines);
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
