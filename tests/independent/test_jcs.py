"""Checks jcs.py against the examples in RFC 8785 and a few more, and merkle.py against itself (2.1.1 vs 2.1.2).

JSON escapes are written %uXXXX in this file and turned into backslash-u at run time.
"""

import os
import struct
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import jcs  # noqa: E402
import merkle  # noqa: E402

BS = chr(92)
failures = []


def u(text):
    return text.replace('%u', BS + 'u')


def check(label, got, want):
    if got != want:
        failures.append(f'{label}: got {got!r}, want {want!r}')


# RFC 8785 3.2.2 input, 3.2.4 expected bytes
sample = u('{\n'
           '  "numbers": [333333333.33333329, 1E30, 4.50,\n'
           '              2e-3, 0.000000000000000000000000001],\n'
           '  "string": "%u20ac$%u000F%u000aA\'%u0042%u0022%u005c' + BS + BS + BS + '"' + BS + '/",\n'
           '  "literals": [null, true, false]\n'
           '}')
want_hex = '''
7b 22 6c 69 74 65 72 61 6c 73 22 3a 5b 6e 75 6c 6c 2c 74 72
75 65 2c 66 61 6c 73 65 5d 2c 22 6e 75 6d 62 65 72 73 22 3a
5b 33 33 33 33 33 33 33 33 33 2e 33 33 33 33 33 33 33 2c 31
65 2b 33 30 2c 34 2e 35 2c 30 2e 30 30 32 2c 31 65 2d 32 37
5d 2c 22 73 74 72 69 6e 67 22 3a 22 e2 82 ac 24 5c 75 30 30
30 66 5c 6e 41 27 42 5c 22 5c 5c 5c 5c 5c 22 2f 22 7d'''
check('RFC 8785 3.2.4 bytes', jcs.canonicalize(jcs.loads(sample)).hex(), bytes.fromhex(want_hex).hex())

# RFC 8785 3.2.3 sorting
sorting = u('{\n'
            '  "%u20ac": "Euro Sign",\n'
            '  "' + BS + 'r": "Carriage Return",\n'
            '  "%ufb33": "Hebrew Letter Dalet With Dagesh",\n'
            '  "1": "One",\n'
            '  "%ud83d%ude00": "Emoji: Grinning Face",\n'
            '  "%u0080": "Control",\n'
            '  "%u00f6": "Latin Small Letter O With Diaeresis"\n'
            '}')
canon = jcs.canonicalize(jcs.loads(sorting))
order = list(jcs.loads(canon.decode('utf-8')).values())
check('RFC 8785 3.2.3 order', order, [
    'Carriage Return', 'One', 'Control', 'Latin Small Letter O With Diaeresis',
    'Euro Sign', 'Emoji: Grinning Face', 'Hebrew Letter Dalet With Dagesh',
])

# RFC 8785 Appendix B
appendix_b = [
    ('0000000000000000', '0'), ('8000000000000000', '0'),
    ('0000000000000001', '5e-324'), ('8000000000000001', '-5e-324'),
    ('7fefffffffffffff', '1.7976931348623157e+308'), ('ffefffffffffffff', '-1.7976931348623157e+308'),
    ('4340000000000000', '9007199254740992'), ('c340000000000000', '-9007199254740992'),
    ('4430000000000000', '295147905179352830000'),
    ('7fffffffffffffff', None), ('7ff0000000000000', None),
    ('44b52d02c7e14af5', '9.999999999999997e+22'), ('44b52d02c7e14af6', '1e+23'),
    ('44b52d02c7e14af7', '1.0000000000000001e+23'),
    ('444b1ae4d6e2ef4e', '999999999999999700000'), ('444b1ae4d6e2ef4f', '999999999999999900000'),
    ('444b1ae4d6e2ef50', '1e+21'),
    ('3eb0c6f7a0b5ed8c', '9.999999999999997e-7'), ('3eb0c6f7a0b5ed8d', '0.000001'),
    ('41b3de4355555553', '333333333.3333332'), ('41b3de4355555554', '333333333.33333325'),
    ('41b3de4355555555', '333333333.3333333'), ('41b3de4355555556', '333333333.3333334'),
    ('41b3de4355555557', '333333333.33333343'),
    ('becbf647612f3696', '-0.0000033333333333333333'),
    ('43143ff3c1cb0959', '1424953923781206.2'),
]
for ieee, want in appendix_b:
    x = struct.unpack('>d', bytes.fromhex(ieee))[0]
    try:
        got = jcs.serialize_number(x)
    except jcs.JcsError:
        got = None
    check(f'Appendix B {ieee}', got, want)

# RFC 8785 Appendix E
check('Appendix E', jcs.canonicalize(jcs.loads('{"time": "2019-01-28T07:45:10Z", "big": "055", "val": 3.5}')),
      b'{"big":"055","time":"2019-01-28T07:45:10Z","val":3.5}')

# More numbers: the layout boundaries of ECMA-262 7.1.12.1
for text, want in [
    ('1e21', '1e+21'), ('1e20', '100000000000000000000'), ('0.000001', '0.000001'), ('1e-7', '1e-7'),
    ('-0', '0'), ('-0.0', '0'), ('0', '0'), ('1', '1'), ('-1', '-1'), ('1.0', '1'), ('1e16', '10000000000000000'),
    ('1e-5', '0.00001'), ('123e-20', '1.23e-18'), ('9999999999999999999', '10000000000000000000'),
    ('9007199254740993', '9007199254740992'), ('0.1', '0.1'), ('100', '100'), ('1.5e300', '1.5e+300'),
]:
    check(f'number {text}', jcs.canonicalize(jcs.loads(text)).decode(), want)

# Strings: non-ASCII as is, controls, the two escapes, solidus and DEL as is
E_ACUTE, EURO, GRIN, LS, PS, DEL = chr(0xE9), chr(0x20AC), chr(0x1F600), chr(0x2028), chr(0x2029), chr(0x7F)
controls = ''.join(chr(c) for c in (0x00, 0x01, 0x07, 0x08, 0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0x0E, 0x1F))
controls_want = '"' + u('%u0000%u0001%u0007') + BS + 'b' + BS + 't' + BS + 'n' + u('%u000b') + BS + 'f' + BS + 'r' + u('%u000e%u001f') + '"'
for value, want in [
    (E_ACUTE, '"' + E_ACUTE + '"'), (EURO, '"' + EURO + '"'), (GRIN, '"' + GRIN + '"'),
    (controls, controls_want),
    ('"' + BS + '/' + DEL, '"' + BS + '"' + BS + BS + '/' + DEL + '"'),
    (LS + PS, '"' + LS + PS + '"'),
]:
    check(f'string {value!r}', jcs.canonicalize(value).decode('utf-8'), want)
check('e-acute bytes', jcs.canonicalize(E_ACUTE), b'"\xc3\xa9"')
check('euro bytes', jcs.canonicalize(EURO), b'"\xe2\x82\xac"')
check('emoji bytes', jcs.canonicalize(GRIN), b'"\xf0\x9f\x98\x80"')
check('escaped emoji in', jcs.canonicalize(jcs.loads(u('"%ud83d%ude00"'))), b'"\xf0\x9f\x98\x80"')
check('escaped solidus in', jcs.canonicalize(jcs.loads('"' + BS + '/"')), b'"/"')

# Must fail
for label, text in [
    ('duplicate', '{"a":1,"a":2}'), ('nested duplicate', '{"x":{"b":1,"b":1}}'),
    ('duplicate through escape', u('{"a":1,"%u0061":2}')), ('lone surrogate', u('"%udead"')),
    ('NaN', 'NaN'), ('Infinity', 'Infinity'), ('overflow', '1e400'),
]:
    try:
        jcs.canonicalize(jcs.loads(text))
        failures.append(f'{label}: accepted')
    except (jcs.JcsError, OverflowError):
        pass

# UTF-16 units against code points: U+FF5E sorts after U+1F600 (D83D DE00) in UTF-16, before it by code point
check('utf-16 vs code point', jcs.canonicalize({chr(0xFF5E): 1, GRIN: 2}).decode('utf-8'),
      '{"' + GRIN + '":2,"' + chr(0xFF5E) + '":1}')

# Merkle: 2.1.1 against 2.1.2, every proof verifies, tampering fails


def sides(m, n):
    """Which side each node of PATH(m, D_n) sits on, from the recursion of 2.1.3.1."""
    if n == 1:
        return []
    k = 1
    while k * 2 < n:
        k *= 2
    return sides(m, k) + ['right'] if m < k else sides(m - k, n - k) + ['left']


for n in range(0, 70):
    entries = [f'e{i}'.encode() for i in range(n)]
    root = merkle.mth(entries)
    check(f'mth vs stack n={n}', root, merkle.mth_stack(entries))
    for m in range(n):
        p = merkle.path(m, entries)
        if not merkle.verify(entries[m], m, n, p, root):
            failures.append(f'proof n={n} m={m} rejected')
        if merkle.verify(entries[m] + b'x', m, n, p, root):
            failures.append(f'altered entry n={n} m={m} accepted')
        for other in range(n + 2):
            if other != m and merkle.verify(entries[m], other, n, p, root):
                failures.append(f'wrong index {other} n={n} m={m} accepted')
        # 2.1.3.2 binds the size only through the fold: a size whose path has the same sides as the true one
        # computes the same root and passes. Expect acceptance exactly then, the sides read from 2.1.3.1.
        for size in range(1, n + 3):
            if size == n or m >= size:
                continue
            accepted = merkle.verify(entries[m], m, size, p, root)
            if accepted != (sides(m, size) == sides(m, n)):
                failures.append(f'size {size} n={n} m={m}: accepted={accepted}, sides {sides(m, size)} vs {sides(m, n)}')
        for j in range(len(p)):
            bad = list(p)
            bad[j] = bytes([bad[j][0] ^ 1]) + bad[j][1:]
            if merkle.verify(entries[m], m, n, bad, root):
                failures.append(f'altered proof hash {j} n={n} m={m} accepted')
        if merkle.verify(entries[m], m, n, p + [root], root) or (p and merkle.verify(entries[m], m, n, p[:-1], root)):
            failures.append(f'proof length change n={n} m={m} accepted')
check('empty tree', merkle.mth([]).hex(), 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')

if failures:
    print('\n'.join(failures[:40]))
    print(f'{len(failures)} failures')
    sys.exit(1)
print('jcs and merkle self-tests: all passed')
