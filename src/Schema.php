<?php

namespace Steddle\Vcrs;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

final class Schema
{
    private const BASE = 'https://vcrs.org/schema/'.Vcrs::VERSION.'/';

    /**
     * The schema errors of a decoded document, as 'pointer: message' lines. Empty when it validates.
     *
     * @return list<string>
     */
    public static function errors(mixed $document, string $schema): array
    {
        $validator = new Validator;
        $validator->setMaxErrors(20);
        $validator->resolver()->registerPrefix(self::BASE, dirname(__DIR__, 2).'/vcrs/schema/');

        $result = $validator->validate($document, self::BASE.$schema.'.schema.json');

        if ($result->isValid()) {
            return [];
        }

        $errors = [];

        foreach ((new ErrorFormatter)->format($result->error(), true) as $pointer => $messages) {
            foreach ($messages as $message) {
                $errors[] = ($pointer === '' ? '/' : $pointer).': '.$message;
            }
        }

        return $errors;
    }
}
