<?php

namespace Steddle\Vcrs;

use RuntimeException;

final class InvalidRegister extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct("The register is not valid:\n".implode("\n", $errors));
    }
}
