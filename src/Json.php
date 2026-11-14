<?php

namespace Steddle\Vcrs;

use Steddle\Seal\Json as Seal;
use Steddle\Seal\Refused;

/**
 * JSON as RFC 8785 reads it, I-JSON (RFC 7493): a member name that appears twice in one object is refused, since
 * parsers disagree on which value wins.
 */
final class Json
{
    /**
     * @throws InvalidRegister
     */
    public static function decode(string $json): mixed
    {
        try {
            return Seal::decode($json);
        } catch (Refused $refused) {
            if ($refused->why === 'duplicate_name') {
                throw new InvalidRegister(["{$refused->pointer}: the member appears more than once in its object."]);
            }

            // Seal refuses only where json_decode failed; decoding again gives back PHP's own reason for the message.
            json_decode($json);

            throw new InvalidRegister(['/: the file is not JSON: '.json_last_error_msg().'.']);
        }
    }
}
