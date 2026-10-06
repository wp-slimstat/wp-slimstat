"""Independent top-family semantics over raw exported rows.

The family receives values. It does not import production code, parse report PHP, build queries,
or read captures. Adapters own transport; this module owns only grouping, ordering, and LIMIT.
"""

def _order_value(value):
    """A total order for nullable scalar dimensions, independent of input row order."""
    if value is None:
        return (0, 0)
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        return (1, value)
    if isinstance(value, bytes):
        return (2, value)
    return (2, str(value).encode("utf-8"))


def _transform(value, transform):
    if value is None or transform is None:
        return value
    if transform == "referer_domain":
        host = str(value).rsplit("://", 1)[-1].split("/", 1)[0]
        return ".".join(host.split(".")[-5:]).replace("www.", "")
    if transform == "platform_prefix":
        return "p-" + str(value)[:3]
    if transform == "trim_trailing_slash":
        return str(value).rstrip("/")
    if transform == "language_prefix":
        return str(value)[:2]
    raise ValueError("unsupported top transform")


def _matches(row, where):
    for column, operator, expected in where:
        if operator == "ne":
            if row[column] is None or row[column] == expected:
                return False
        elif operator == "contains_ascii_ci":
            if row[column] is None:
                return False
            try:
                value = row[column].decode("ascii") if isinstance(row[column], bytes) else str(row[column]).encode("ascii").decode()
                needle = expected.decode("ascii") if isinstance(expected, bytes) else str(expected).encode("ascii").decode()
            except UnicodeError as error:
                raise ValueError("ASCII LIKE cannot model non-ASCII collation semantics") from error
            if needle.lower() not in value.lower():
                return False
        elif operator != "not_in":
            raise ValueError("unsupported top predicate")
        elif row[column] is None or row[column] in expected:
            return False
    return True


def _equality_key(value, equality):
    if value is None or equality == "binary":
        return value
    if equality != "ascii_ci" or not isinstance(value, (bytes, str)):
        raise ValueError("unsupported top equality")
    try:
        raw = value if isinstance(value, bytes) else value.encode("ascii")
    except UnicodeEncodeError as error:
        raise ValueError("ascii_ci top cannot model non-ASCII collation semantics") from error
    if any(byte > 127 for byte in raw):
        raise ValueError("ascii_ci top cannot model non-ASCII collation semantics")
    return raw.rstrip(b" ").lower()


def rank_top(rows, dimension, grain=("blog_id",), limit=None, transform=None, exclude_null=False,
             where=(), start=None, end=None, equality="binary"):
    """Count rows by grain+dimension, order deterministically, then apply LIMIT."""
    dimensions = (dimension,) if isinstance(dimension, str) else tuple(dimension)
    if not dimensions or any(not isinstance(name, str) or not name for name in dimensions):
        raise ValueError("top dimension must name at least one field")
    if transform is not None and len(dimensions) != 1:
        raise ValueError("top transform requires one dimension")
    if not grain:
        raise ValueError("top grain must name at least one field")
    if limit is not None and (not isinstance(limit, int) or isinstance(limit, bool) or limit < 1):
        raise ValueError("top limit must be a positive integer or None")
    if (start is None) != (end is None) or (start is not None and start > end):
        raise ValueError("top window must have ordered start and end values")

    counts, displayed = {}, {}
    for index, row in enumerate(rows):
        if not isinstance(row, dict):
            raise ValueError("top row %d is not an object" % index)
        required = tuple(grain) + dimensions + tuple(item[0] for item in where) + (() if start is None else ("dt",))
        missing = [name for name in required if name not in row]
        if missing:
            raise ValueError("top row %d is missing %s" % (index, ", ".join(missing)))
        if (start is not None and not start <= row["dt"] <= end) or not _matches(row, where):
            continue
        if exclude_null and any(row[name] is None for name in dimensions):
            continue
        values = tuple(_transform(row[name], transform) for name in dimensions)
        key = tuple(row[name] for name in grain) + tuple(_equality_key(value, equality) for value in values)
        displayed.setdefault(key, values)
        counts[key] = counts.get(key, 0) + 1

    ranked = []
    for key, count in counts.items():
        item = {name: key[pos] for pos, name in enumerate(grain)}
        item.update(zip(dimensions, displayed[key]))
        item["counthits"] = count
        ranked.append(item)

    ranked.sort(key=lambda item: (
        -item["counthits"],
        tuple(_order_value(_equality_key(item[name], equality)) for name in dimensions),
        tuple(_order_value(item[name]) for name in grain),
    ))
    return ranked if limit is None else ranked[:limit]


def rank_recent_top(rows, dimensions, grain, limit, start, end, equality="binary", where=()):
    """Count grouped values and order them by their latest hit in a pinned window."""
    dimensions = (dimensions,) if isinstance(dimensions, str) else tuple(dimensions)
    ranked = rank_top(rows, dimensions, grain, None, where=where, start=start, end=end, equality=equality)
    latest = {}
    for row in rows:
        if start <= row["dt"] <= end and _matches(row, where):
            key = tuple(row[name] for name in grain) + tuple(
                _equality_key(row[name], equality) for name in dimensions)
            latest[key] = max(latest.get(key, row["dt"]), row["dt"])
    for item in ranked:
        key = tuple(item[name] for name in grain) + tuple(
            _equality_key(item[name], equality) for name in dimensions)
        item["dt"] = latest[key]
    ranked.sort(key=lambda item: (
        -item["dt"],
        tuple(_order_value(_equality_key(item[name], equality)) for name in dimensions),
        tuple(_order_value(item[name]) for name in grain),
    ))
    return ranked[:limit]


def rank_current(rows, dimension, grain, limit, end, active_columns, include_max_dt=False,
                 exclude_empty=False, equality="binary"):
    """Rank values active in the strict five-minute report window."""
    active = [row for row in rows if any(row[column] is not None and row[column] > end - 300
                                         for column in active_columns)
              and not (exclude_empty and row[dimension] in (None, ""))]
    ranked = rank_top(active, dimension, grain, None, equality=equality)
    if include_max_dt:
        latest = {}
        for row in active:
            key = tuple(row[name] for name in grain) + (_equality_key(row[dimension], equality),)
            latest[key] = max(latest.get(key, row["dt"]), row["dt"])
        for row in ranked:
            row["dt"] = latest[tuple(row[name] for name in grain)
                               + (_equality_key(row[dimension], equality),)]
        ranked.sort(key=lambda row: (-row["dt"], _order_value(_equality_key(row[dimension], equality))))
    return ranked[:limit]


def evaluate(report_key, contract, rows, limit):
    """Return one report envelope joined to the contract's real report id."""
    if contract.get("family") != "top":
        raise ValueError("%s is not a top-family contract" % report_key)
    answer = rank_top(
        rows,
        contract["dimension"],
        tuple(contract["grain"]),
        limit,
        contract.get("transform"),
        contract.get("exclude_null", False),
    )
    return {
        "key": report_key,
        "report_id": contract["report_id"],
        "family": "top",
        "rows": answer,
    }


def top_events(events, start, end, limit):
    rows = [dict(row, blog_id=1) for row in events
            if start <= row['dt'] <= end and row['notes'] is not None
            and not row['notes'].lower().startswith('type:click')]
    value = [{"notes": row["notes"], "counthits": row["counthits"]}
             for row in rank_top(rows, "notes", ("blog_id",), limit)]
    return value


def top_outbound(rows, start, end, limit):
    recent = sorted((row for row in rows if start <= row['dt'] <= end
                     and row['outbound_resource'] not in (None, '')),
                    key=lambda row: (row['dt'], row['dt_out']), reverse=True)[:limit]
    grouped = {}
    for row in recent:
        dt = row['dt_out'] if row['dt_out'] and row['dt_out'] > 0 else row['dt']
        for url in row['outbound_resource'].split(';;;'):
            if url:
                count, latest = grouped.get(url, (0, 0))
                grouped[url] = count + 1, max(latest, dt)
    value = [{'outbound_resource': url, 'counthits': count, 'dt': dt}
             for url, (count, dt) in grouped.items()]
    return sorted(value, key=lambda row: (row['counthits'], row['dt']), reverse=True)
