<?php

namespace Steddle\Vcrs;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * A facts export (section 11.1), read from one JSON file, or from a JSON file for the register
 * and a CSV file for its records.
 */
final readonly class Register
{
    /**
     * @param  array<string, mixed>  $data  the export as arrays, for the rules
     * @param  list<stdClass>  $records  each record as decoded, for its digest
     */
    private function __construct(
        public array $data,
        public array $records,
    ) {}

    public static function read(string $path, ?string $csv = null): self
    {
        $document = self::decode($path);

        if ($csv !== null) {
            $document->records = Csv::records($csv);
        }

        $errors = Schema::errors($document, 'export');

        if ($errors !== []) {
            throw new InvalidRegister($errors);
        }

        return new self(
            json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
            $document->records,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function flow(string $id): ?array
    {
        foreach ($this->data['flows'] as $flow) {
            if ($flow['id'] === $id) {
                return $flow;
            }
        }

        return null;
    }

    /**
     * @return array<string, bool>
     */
    public function informing(): array
    {
        $informing = [];

        foreach ($this->data['flows'] as $flow) {
            $informing[$flow['id']] = ! in_array(false, $flow['states'], true);
        }

        return $informing;
    }

    private static function decode(string $path): stdClass
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("No file at {$path}.");
        }

        try {
            $document = json_decode((string) file_get_contents($path), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new InvalidRegister(["/: the file is not JSON: {$invalid->getMessage()}."]);
        }

        if (! $document instanceof stdClass) {
            throw new InvalidRegister(['/: the file holds no JSON object.']);
        }

        return $document;
    }
}
