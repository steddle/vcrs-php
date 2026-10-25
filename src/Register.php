<?php

namespace Steddle\Vcrs;

use InvalidArgumentException;
use stdClass;

/**
 * A facts export (section 11.1), read from one JSON file, or from a JSON file for the register
 * and a CSV file for its records.
 */
final readonly class Register
{
    /**
     * @param  array<string, mixed>  $data  the export as arrays, for the rules
     * @param  stdClass  $document  the export as decoded, for its manifest
     * @param  list<stdClass>  $records  each record as decoded, for its digest
     */
    private function __construct(
        public array $data,
        public stdClass $document,
        public array $records,
    ) {}

    /**
     * @throws InvalidRegister
     */
    public static function read(string $path, ?string $csv = null): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("No file at {$path}.");
        }

        $document = Json::decode((string) file_get_contents($path));

        if (! $document instanceof stdClass) {
            throw new InvalidRegister(['/: the file holds no JSON object.']);
        }

        if (isset($document->vcrs) && $document->vcrs !== Vcrs::VERSION) {
            throw new InvalidRegister(['/vcrs: this validator implements VCRS '.Vcrs::VERSION.', and the export names '.json_encode($document->vcrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'.']);
        }

        if ($csv !== null) {
            $document->records = Csv::records($csv);
        }

        $errors = Schema::errors($document, 'export');

        if ($errors !== []) {
            throw new InvalidRegister($errors);
        }

        return new self(
            json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
            $document,
            $document->records,
        );
    }
}
