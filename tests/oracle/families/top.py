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
    raise ValueError("unsupported top transform")


def rank_top(rows, dimension, grain=("blog_id",), limit=None, transform=None, exclude_null=False):
    """Count rows by grain+dimension, order deterministically, then apply LIMIT."""
    if not isinstance(dimension, str) or not dimension:
        raise ValueError("top dimension must be a non-empty string")
    if not grain:
        raise ValueError("top grain must name at least one field")
    if limit is not None and (not isinstance(limit, int) or isinstance(limit, bool) or limit < 1):
        raise ValueError("top limit must be a positive integer or None")

    counts = {}
    for index, row in enumerate(rows):
        if not isinstance(row, dict):
            raise ValueError("top row %d is not an object" % index)
        missing = [name for name in tuple(grain) + (dimension,) if name not in row]
        if missing:
            raise ValueError("top row %d is missing %s" % (index, ", ".join(missing)))
        if exclude_null and row[dimension] is None:
            continue
        key = tuple(row[name] for name in grain) + (_transform(row[dimension], transform),)
        counts[key] = counts.get(key, 0) + 1

    ranked = []
    for key, count in counts.items():
        item = {name: key[pos] for pos, name in enumerate(grain)}
        item[dimension] = key[-1]
        item["counthits"] = count
        ranked.append(item)

    ranked.sort(key=lambda item: (
        -item["counthits"],
        _order_value(item[dimension]),
        tuple(_order_value(item[name]) for name in grain),
    ))
    return ranked if limit is None else ranked[:limit]


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
