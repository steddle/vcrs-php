"""A second implementation of VCRS core 5.4 and 11.3, written from the standard and the RFCs alone, run over what the
standard publishes: every vector's digest, and the example's records, snapshot root, manifest and inclusion proofs.

  check.py <standard folder>

Python 3 standard library only. Run with python3 -I.
"""

import glob
import hashlib
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import jcs  # noqa: E402
import merkle  # noqa: E402


def read(path):
    with open(path, encoding='utf-8') as f:
        return jcs.loads(f.read())


def digest(value):
    return 'sha256:' + hashlib.sha256(jcs.canonicalize(value)).hexdigest()


def record_digest(record):
    """Core 11.3: RFC 8785 of the record without its digest member."""
    return digest({k: v for k, v in record.items() if k != 'digest'})


def lines(records):
    """Core 5.4 step 1: '<id> <digest>', ordered by identifier in byte order."""
    return [f"{r['id']} {record_digest(r)}".encode('utf-8') for r in sorted(records, key=lambda r: r['id'].encode('utf-8'))]


def manifest(export, root):
    """Core 5.4 step 3: the export without its anchor, its records as their number and the root."""
    m = {k: v for k, v in export.items() if k != 'anchor'}
    m['records'] = {'count': len(export['records']), 'root': root}
    return m


def main(standard):
    failures = []
    checks = 0

    def check(label, got, want):
        nonlocal checks
        checks += 1
        if got != want:
            failures.append(f'{label}: got {got!r}, want {want!r}')

    for path in sorted(glob.glob(os.path.join(standard, 'vectors', '*', '*.json'))):
        vector = read(path)
        check(os.path.relpath(path, standard), record_digest(vector['record']), vector['expected']['digest'])

    templates = os.path.join(standard, 'templates')
    export = read(os.path.join(templates, 'export.json'))
    expected = read(os.path.join(templates, 'expected.json'))

    for record in export['records']:
        check(f"record {record['id']}", record_digest(record), record['digest'])
        check(f"expected {record['id']}", record_digest(record), expected['records'][record['id']]['digest'])

    root = merkle.to_text(merkle.mth(lines(export['records'])))
    check('root', root, export['anchor']['root'])
    check('root in expected.json', root, expected['root'])

    m = manifest(export, root)
    check('manifest', digest(m), export['anchor']['manifest'])
    check('manifest in expected.json', digest(m), expected['manifest'])
    check('manifest.json', read(os.path.join(templates, 'manifest.json')), m)

    for name, holds in (('proof.json', True), ('proof-altered.json', False)):
        proof = read(os.path.join(templates, name))
        held = merkle.verify(proof['line'].encode('utf-8'), proof['index'], proof['size'], [merkle.from_text(p) for p in proof['proof']], merkle.from_text(root))
        check(f'{name} holds', held, holds)

    if failures:
        print('\n'.join(failures))
        print(f'{len(failures)} of {checks} checks failed')
        return 1

    print(f'{checks} checks agree')
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1]))
