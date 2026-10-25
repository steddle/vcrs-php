"""RFC 9162 section 2.1 Merkle Tree Hash and inclusion proofs, SHA-256, written from the RFC text alone."""

import hashlib


def sha256(data):
    return hashlib.sha256(data).digest()


def leaf_hash(entry):
    return sha256(b'\x00' + entry)


def node_hash(left, right):
    return sha256(b'\x01' + left + right)


def _split(n):
    k = 1
    while k * 2 < n:
        k *= 2
    return k


def mth(entries):
    """2.1.1, recursive."""
    n = len(entries)
    if n == 0:
        return sha256(b'')
    if n == 1:
        return leaf_hash(entries[0])
    k = _split(n)
    return node_hash(mth(entries[:k]), mth(entries[k:]))


def mth_stack(entries):
    """2.1.2, the stack algorithm: a second reading of the same tree, to check mth against."""
    if not entries:
        return sha256(b'')
    stack = []
    for i, entry in enumerate(entries):
        stack.append(leaf_hash(entry))
        merge_count = 0
        while (i >> merge_count) & 1:
            merge_count += 1
        for _ in range(merge_count):
            right = stack.pop()
            left = stack.pop()
            stack.append(node_hash(left, right))
    while len(stack) > 1:
        right = stack.pop()
        left = stack.pop()
        stack.append(node_hash(left, right))
    return stack[0]


def path(m, entries):
    """2.1.3.1, PATH(m, D_n)."""
    n = len(entries)
    if not 0 <= m < n:
        raise ValueError(f'index {m} outside a tree of {n}')
    if n == 1:
        return []
    k = _split(n)
    if m < k:
        return path(m, entries[:k]) + [mth(entries[k:])]
    return path(m - k, entries[k:]) + [mth(entries[:k])]


def verify(entry, leaf_index, tree_size, inclusion_path, root_hash):
    """2.1.3.2, step by step."""
    if leaf_index >= tree_size or leaf_index < 0:
        return False
    fn = leaf_index
    sn = tree_size - 1
    r = leaf_hash(entry)
    for p in inclusion_path:
        if sn == 0:
            return False
        if fn & 1 or fn == sn:
            r = node_hash(p, r)
            if not fn & 1:
                while not fn & 1 and fn != 0:
                    fn >>= 1
                    sn >>= 1
        else:
            r = node_hash(r, p)
        fn >>= 1
        sn >>= 1
    return sn == 0 and r == root_hash


def to_text(h):
    return 'sha256:' + h.hex()


def from_text(text):
    if not isinstance(text, str) or not text.startswith('sha256:') or len(text) != 71:
        raise ValueError(f'not a sha256: digest: {text!r}')
    hexpart = text[7:]
    if hexpart != hexpart.lower():
        raise ValueError(f'not lowercase hex: {text!r}')
    return bytes.fromhex(hexpart)
