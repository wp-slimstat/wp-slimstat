#!/usr/bin/env python3
"""Hand-derived acceptance checks for the independent group-by family (slim_p4_27)."""

import json
from pathlib import Path

from families.group_by import grouped_concat


root = Path(__file__).resolve().parent
contract = json.loads((root / 'report-contracts.json').read_text())['reports']['get_group_by']
assert contract['report_id'] == 'slim_p4_27' and contract['family'] == 'group_by'
assert contract['default_limit'] == 200 and contract['equality'] == 'ascii_ci'

# The capture pins low-cardinality columns on purpose: the concatenated member list is bounded
# only by group_concat_max_len, and a truncated list is not a comparable answer. The contract
# has to name the SAME two columns the capture asked for, or the model answers another query.
capture = (root.parent / 'docker' / 'report-answers.php').read_text()
start = capture.index("$capture_windowed('get_group_by'")
call = capture[start:start + 300]
assert "'group_by' => '%s'" % contract['group_by'] in call
assert "'column_group' => '%s'" % contract['column_group'] in call


rows = [
    {'browser': b'Chrome', 'platform': b'Win', 'dt': 100},
    {'browser': b'Chrome', 'platform': b'Mac', 'dt': 110},   # second member for the group
    {'browser': b'Chrome', 'platform': b'Win', 'dt': 120},   # duplicate member, counted once
    {'browser': b'Chrome', 'platform': None, 'dt': 130},     # counted, but contributes no member
    {'browser': b'Firefox', 'platform': b'Mac', 'dt': 140},
    {'browser': b'Firefox', 'platform': b'Win', 'dt': 150},
    {'browser': b'Safari', 'platform': None, 'dt': 160},     # every member absent -> no list
    {'browser': None, 'platform': b'Win', 'dt': 170},        # absent group value: dropped
    {'browser': b'Edge', 'platform': b'Win', 'dt': 900},     # outside the window: dropped
    {'browser': b'Opera', 'platform': b'Win', 'dt': 10},     # before the window: dropped
]

answer = grouped_concat(rows, 50, 200, 'browser', 'platform', 10)
assert answer == [
    {'browser': 'Chrome', 'counthits': 4, 'column_group': 'Mac;;;Win'},
    {'browser': 'Firefox', 'counthits': 2, 'column_group': 'Mac;;;Win'},
    {'browser': 'Safari', 'counthits': 1, 'column_group': None},
], answer

# Ranking is by count first; the LIMIT is applied AFTER the order, never before it.
assert [row['browser'] for row in grouped_concat(rows, 50, 200, 'browser', 'platform', 2)] \
    == ['Chrome', 'Firefox']
assert [row['browser'] for row in grouped_concat(rows, 50, 200, 'browser', 'platform', 1)] \
    == ['Chrome']

# Ties fall back to the group value, ascending, on its collation key rather than its bytes.
tied = [{'browser': b'beta', 'platform': b'x', 'dt': 1},
        {'browser': b'Alpha', 'platform': b'x', 'dt': 2}]
assert [row['browser'] for row in grouped_concat(tied, 0, 10, 'browser', 'platform', 10)] \
    == ['Alpha', 'beta']

# The window is inclusive at both ends.
edge = [{'browser': b'a', 'platform': b'x', 'dt': 50}, {'browser': b'b', 'platform': b'x', 'dt': 200}]
assert len(grouped_concat(edge, 50, 200, 'browser', 'platform', 10)) == 2

# Binary equality keeps two spellings apart where the collation key folds them together —
# which is also why the ci model refuses the same pair rather than choosing a winner.
spellings = [{'browser': b'Chrome', 'platform': b'x', 'dt': 60},
             {'browser': b'chrome', 'platform': b'x', 'dt': 70}]
assert len(grouped_concat(spellings, 50, 200, 'browser', 'platform', 10, equality='binary')) == 2


def rejects(message, *args, **kwargs):
    try:
        grouped_concat(*args, **kwargs)
    except ValueError:
        return
    raise AssertionError(message)


# Two spellings that collate equal land in one group and the engine returns whichever it read;
# the model refuses rather than picking one and calling the other arm wrong.
rejects('ambiguous group spelling accepted',
        [{'browser': b'Chrome', 'platform': b'x', 'dt': 1},
         {'browser': b'CHROME', 'platform': b'x', 'dt': 2}], 0, 10, 'browser', 'platform', 10)
rejects('ambiguous member spelling accepted',
        [{'browser': b'a', 'platform': b'Win', 'dt': 1},
         {'browser': b'a', 'platform': b'WIN', 'dt': 2}], 0, 10, 'browser', 'platform', 10)
rejects('non-ASCII collation accepted',
        [{'browser': 'Chrøme'.encode('utf-8'), 'platform': b'x', 'dt': 1}],
        0, 10, 'browser', 'platform', 10)
rejects('inverted window accepted', rows, 200, 50, 'browser', 'platform', 10)
rejects('non-integer window accepted', rows, '50', 200, 'browser', 'platform', 10)
rejects('zero limit accepted', rows, 50, 200, 'browser', 'platform', 0)
rejects('boolean limit accepted', rows, 50, 200, 'browser', 'platform', True)
rejects('row missing a consumed column accepted',
        [{'browser': b'a', 'dt': 1}], 0, 10, 'browser', 'platform', 10)

print('PASS: group-by preserves window, absent values, collation grouping, member dedup, order and LIMIT')
