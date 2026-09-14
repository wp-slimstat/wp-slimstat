#!/usr/bin/env python3
"""The blind-proof gate on C2's vacuity control, pinned against the shipped bytes.

O3 lets a surface that is EMPTY on both arms be a NOTE instead of a FAIL, but only when an
in-container count of its source predicate came back 0. That exemption is one `cnt == 0`
away from excusing every quiet surface, so this test does not read the script for a string —
it EXTRACTS the classifier out of compare-answers.sh and runs it. A proof of 0 is the only
thing that may excuse; a count above 0, a missing table (null), and no record at all must all
stay in the FAIL list that aborts the run.
"""
import re
import textwrap
from pathlib import Path

script_src = Path(__file__).with_name('compare-answers.sh').read_text()

classifier = re.search(
    # Matched on the loop's SHAPE, never on its condition: an extraction keyed to `cnt == 0`
    # would report a mutated condition as "classifier is gone" and never execute the thing it
    # claims to test. This pattern still extracts a loosened classifier, so the assertions below
    # are what fails.
    r'\n(    blind, vacuous = \[\], \[\]\n(?:.*\n)*?.*\.append\(k\)\n)',
    script_src)
assert classifier, 'the blind/vacuous classifier is gone from compare-answers.sh'

ns = {
    'empty_both': ['proven_blind', 'has_rows', 'table_missing', 'unclaimed'],
    'blind_proof': {
        'proven_blind': {'table': 'slim_stats', 'where': "content_type = 'download'", 'count': 0},
        'has_rows': {'table': 'slim_stats', 'where': "content_type = 'download'", 'count': 7},
        'table_missing': {'table': 'slim_events', 'where': '1', 'count': None},
    },
}
exec(textwrap.dedent(classifier.group(1)), ns)

assert ns['blind'] == ['proven_blind'], ns['blind']
assert ns['vacuous'] == ['has_rows', 'table_missing', 'unclaimed'], ns['vacuous']

# ...and the FAIL list is still what aborts. A classifier that sorts correctly into a list
# nothing reads would pass everything above.
abort = re.search(r'\n    if vacuous:\n(?:.*\n)*?.*sys\.exit\(1\)\n', script_src)
assert abort, 'the vacuity abort no longer keys off the `vacuous` list'
assert 'VERDICT: ABORTED' in abort.group(0)

# The cardinality control may only be softened by SLIMSTAT_C2_CORPUS=real, never by default.
assert "corpus_kind = (os.environ.get('SLIMSTAT_C2_CORPUS') or 'synthetic')" in script_src
assert "elif same_arm or (thin_corpus and corpus_kind == 'synthetic'):" in script_src

print('PASS: only a proven-zero predicate excuses a doubly-empty surface; '
      'rows, a missing table and no record all still abort')
