"""RFC 8785 JSON Canonicalization Scheme, written from the RFC text alone."""

import decimal
import json
import math


class JcsError(ValueError):
    pass


def _reject_duplicates(pairs):
    seen = set()
    for key, _ in pairs:
        if key in seen:
            raise JcsError(f'duplicate property name {key!r} (RFC 8785 3.1, I-JSON)')
        seen.add(key)
    return dict(pairs)


def _reject_constant(name):
    raise JcsError(f'{name} is not JSON (RFC 8785 3.2.2.3)')


def loads(text):
    """Parse JSON as I-JSON requires: no duplicate names, no NaN or Infinity."""
    return json.loads(text, object_pairs_hook=_reject_duplicates, parse_constant=_reject_constant)


_SHORT = {0x08: '\\b', 0x09: '\\t', 0x0A: '\\n', 0x0C: '\\f', 0x0D: '\\r'}


def serialize_string(s):
    out = ['"']
    for ch in s:
        o = ord(ch)
        if 0xD800 <= o <= 0xDFFF:
            raise JcsError(f'lone surrogate U+{o:04X} (RFC 8785 3.2.2.2)')
        if ch == '"':
            out.append('\\"')
        elif ch == '\\':
            out.append('\\\\')
        elif o < 0x20:
            out.append(_SHORT.get(o, f'\\u{o:04x}'))
        else:
            out.append(ch)
    out.append('"')
    return ''.join(out)


def serialize_number(value):
    """ECMA-262 Number::toString (7.1.12.1) with Note 2, for an IEEE 754 double."""
    x = float(value)  # an integer goes through the double it denotes; OverflowError past the range
    if math.isnan(x) or math.isinf(x):
        raise JcsError('NaN and Infinity are not JSON (RFC 8785 3.2.2.3)')
    if x == 0:
        return '0'
    if x < 0:
        return '-' + serialize_number(-x)
    # repr gives the shortest digit string that round-trips, ties to the closer and then even: s and k of ECMA-262.
    sign, digits, exponent = decimal.Decimal(repr(x)).as_tuple()
    digits = list(digits)
    while len(digits) > 1 and digits[-1] == 0:
        digits.pop()
        exponent += 1
    s = ''.join(map(str, digits))
    k = len(s)
    n = k + exponent
    if k <= n <= 21:
        return s + '0' * (n - k)
    if 0 < n <= 21:
        return s[:n] + '.' + s[n:]
    if -6 < n <= 0:
        return '0.' + '0' * (-n) + s
    e = n - 1
    exp = ('+' if e >= 0 else '-') + str(abs(e))
    if k == 1:
        return s + 'e' + exp
    return s[0] + '.' + s[1:] + 'e' + exp


def _utf16_units(key):
    return key.encode('utf-16-be', 'surrogatepass')


def _serialize(value):
    if value is None:
        return 'null'
    if value is True:
        return 'true'
    if value is False:
        return 'false'
    if isinstance(value, str):
        return serialize_string(value)
    if isinstance(value, (int, float)):
        return serialize_number(value)
    if isinstance(value, (list, tuple)):
        return '[' + ','.join(_serialize(v) for v in value) + ']'
    if isinstance(value, dict):
        keys = list(value.keys())
        for key in keys:
            if not isinstance(key, str):
                raise JcsError(f'property name {key!r} is not a string')
        keys.sort(key=_utf16_units)
        return '{' + ','.join(serialize_string(k) + ':' + _serialize(value[k]) for k in keys) + '}'
    raise JcsError(f'{type(value).__name__} is not JSON')


def canonicalize(value):
    """The canonical form as UTF-8 bytes (RFC 8785 3.2.4)."""
    return _serialize(value).encode('utf-8')
