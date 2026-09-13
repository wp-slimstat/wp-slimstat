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


def count_values(rows, column, distinct=False, start=None, end=None, equality='binary'):
    if not isinstance(column, str) or not column:
        raise ValueError('count column must be a non-empty string')
    if (start is None) != (end is None) or (start is not None and start > end):
        raise ValueError('count window must have ordered start and end values')

    values = []
    for index, row in enumerate(rows):
        required = (column, 'dt') if start is not None else (column,)
        if not isinstance(row, dict) or any(name not in row for name in required):
            raise ValueError('count row %d lacks a consumed field' % index)
        if start is not None and not start <= row['dt'] <= end:
            continue
        if row[column] is not None:
            values.append(row[column])
    return len({_distinct_key(value, equality) for value in values}) if distinct else len(values)
