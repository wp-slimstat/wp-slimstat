"""Independent scalar COUNT semantics over exported rows."""


def _distinct_key(value, equality):
    if equality == 'binary':
        return value
    if equality != 'ascii_ci' or not isinstance(value, (bytes, str)):
        raise ValueError('unsupported count equality')
    raw = value if isinstance(value, bytes) else value.encode('ascii')
    if any(byte > 127 for byte in raw):
        raise ValueError('ascii_ci count cannot model non-ASCII collation semantics')
    return raw.rstrip(b' ').lower()


def count_values(rows, column, distinct=False, start=None, end=None, equality='binary', where_not_equal=None):
    if not isinstance(column, str) or not column:
        raise ValueError('count column must be a non-empty string')
    if (start is None) != (end is None) or (start is not None and start > end):
        raise ValueError('count window must have ordered start and end values')

    values = []
    for index, row in enumerate(rows):
        required = ((column, 'dt') if start is not None else (column,)) + ((where_not_equal[0],) if where_not_equal else ())
        if not isinstance(row, dict) or any(name not in row for name in required):
            raise ValueError('count row %d lacks a consumed field' % index)
        if start is not None and not start <= row['dt'] <= end:
            continue
        if where_not_equal and (row[where_not_equal[0]] is None or row[where_not_equal[0]] == where_not_equal[1]):
            continue
        if row[column] is not None:
            values.append(row[column])
    return len({_distinct_key(value, equality) for value in values}) if distinct else len(values)


def count_singletons(rows, group_column, counted_column, where, start, end, equality='binary'):
    """Count grouped values having exactly one non-NULL counted value."""
    if any(len(item) != 3 or item[1] not in ('gt', 'ne') for item in where):
        raise ValueError('unsupported singleton predicate')
    counts = {}
    for index, row in enumerate(rows):
        required = {group_column, counted_column, 'dt'} | {item[0] for item in where}
        if not isinstance(row, dict) or any(name not in row for name in required):
            raise ValueError('singleton row %d lacks a consumed field' % index)
        if not start <= row['dt'] <= end:
            continue
        if any(row[column] is None or (operator == 'gt' and row[column] <= value)
               or (operator == 'ne' and row[column] == value)
               for column, operator, value in where):
            continue
        group = None if row[group_column] is None else _distinct_key(row[group_column], equality)
        counts[group] = counts.get(group, 0) + (row[counted_column] is not None)
    return sum(count == 1 for count in counts.values())
