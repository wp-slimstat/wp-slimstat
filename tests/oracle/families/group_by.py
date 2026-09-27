"""Independent model for get_group_by — grouped counts with a concatenated member list."""

from collections import defaultdict


SEPARATOR = ";;;"


def _key(value, equality):
    """The collation key that grouping and deduplication compare on."""
    if equality == "binary":
        return value
    raw = value if isinstance(value, bytes) else value.encode("ascii")
    if equality != "ascii_ci" or any(byte > 127 for byte in raw):
        raise ValueError("group-by cannot model non-ASCII collation semantics")
    return raw.rstrip(b" ").lower()


def _display(displays, key, raw, what):
    # Two spellings that collate equal land in one group, and which one the engine returns is a
    # property of the rows it happened to read. A corpus that contains both is a corpus this
    # model cannot answer for, so it says so instead of picking.
    if key in displays and displays[key] != raw:
        raise ValueError("group-by has an ambiguous collation-equivalent %s" % what)
    displays[key] = raw
    return raw


def grouped_concat(rows, start, end, group_by, column_group, limit, equality="ascii_ci"):
    """Count rows per group and concatenate each group's distinct member values.

    Rows outside the pinned window, and rows whose group value is absent, are dropped. Groups
    rank by descending count and then by the group value; the limit applies after that order.
    """
    if type(start) is not int or type(end) is not int or start > end:
        raise ValueError("group-by window must be inclusive integer bounds")
    if type(limit) is not int or limit < 1:
        raise ValueError("group-by limit must be a positive integer")

    counts = defaultdict(int)
    members = defaultdict(dict)
    group_display, member_display = {}, {}
    for index, row in enumerate(rows):
        if not isinstance(row, dict) or any(
                column not in row for column in (group_by, column_group, "dt")):
            raise ValueError("group-by row %d lacks a consumed column" % index)
        if row["dt"] is None or not start <= row["dt"] <= end or row[group_by] is None:
            continue
        group = _key(row[group_by], equality)
        _display(group_display, group, row[group_by], group_by)
        # The row counts whether or not its member value is absent; the concatenation skips
        # absent values. The two aggregates disagree on purpose.
        counts[group] += 1
        if row[column_group] is not None:
            member = _key(row[column_group], equality)
            members[group][member] = _display(member_display, member, row[column_group], column_group)

    ordered = sorted(counts, key=lambda group: (-counts[group], group))
    result = []
    for group in ordered[:limit]:
        # The distinct member list is deduped through a sorted tree, so members come back in
        # collation order rather than in the order the rows were read.
        joined = SEPARATOR.join(
            _text(members[group][member]) for member in sorted(members[group]))
        result.append({group_by: _text(group_display[group]), "counthits": counts[group],
                       "column_group": joined if members[group] else None})
    return result


def _text(value):
    return value.decode("ascii") if isinstance(value, bytes) else value
