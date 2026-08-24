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


def rank_top(rows, dimension, grain=("blog_id",), limit=None):
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
        key = tuple(row[name] for name in grain) + (row[dimension],)
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
    )
    return {
        "key": report_key,
        "report_id": contract["report_id"],
        "family": "top",
        "rows": answer,
    }
