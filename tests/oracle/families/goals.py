"""Independent goal and two-step funnel semantics for pinned capture fixtures."""

from decimal import Decimal, ROUND_HALF_UP


def _ascii(value):
    if value is None:
        return None
    raw = value if isinstance(value, bytes) else str(value).encode("ascii")
    if any(byte > 127 for byte in raw):
        raise ValueError("goal model cannot guess non-ASCII collation semantics")
    return raw.rstrip(b" ").lower()


def _identity(row):
    if row["fingerprint"] is not None:
        return _ascii(row["fingerprint"])
    if row["visit_id"] is not None:
        return _ascii("v_" + str(row["visit_id"]))
    return None if row["ip"] is None else _ascii(b"ip_" + row["ip"])


def _selected(rows, start, end):
    required = ("id", "resource", "browser", "country", "fingerprint", "visit_id", "ip", "dt")
    answer = []
    for index, row in enumerate(rows):
        if not isinstance(row, dict) or any(name not in row for name in required):
            raise ValueError("goal row %d lacks a consumed field" % index)
        if start <= row["dt"] <= end:
            answer.append(row)
    return answer


def _matches(row, rule):
    value = row[rule["dimension"]]
    if rule["operator"] == "contains":
        return value is not None and _ascii(rule["value"]) in _ascii(value)
    if rule["operator"] == "is_not_empty":
        return value is not None and _ascii(value) != b""
    raise ValueError("unsupported pinned goal operator")


def _rounded_percent(numerator, denominator, places):
    if not denominator:
        return 0.0
    quantum = Decimal(1).scaleb(-places)
    value = (Decimal(100 * numerator) / denominator).quantize(quantum, ROUND_HALF_UP)
    return int(value) if value == value.to_integral() else float(value)


def goal_results(rows, start, end, rule):
    selected = _selected(rows, start, end)
    matched = [row for row in selected if _matches(row, rule)]
    uniques = len({_identity(row) for row in matched})
    total_visitors = len({_identity(row) for row in selected})
    return {"total": len(matched), "uniques": uniques,
            "cr": _rounded_percent(uniques, total_visitors, 2),
            "total_visitors": total_visitors}


def funnel_results(rows, start, end, steps):
    selected = _selected(rows, start, end)
    previous = {}
    results = []
    first_count = 0
    for index, step in enumerate(steps):
        matches = {}
        for row in selected:
            identity = _identity(row)
            if not _matches(row, step):
                continue
            if index and (identity not in previous or row["dt"] < previous[identity][0]
                          or row["id"] == previous[identity][1]):
                continue
            candidate = (row["dt"], row["id"])
            if identity not in matches or candidate < matches[identity]:
                matches[identity] = candidate
        visitors = len(matches)
        if index == 0:
            first_count = visitors
        prior = results[-1]["visitors"] if results else visitors
        results.append({"name": step["name"], "visitors": visitors,
                        "pct": _rounded_percent(visitors, first_count, 1),
                        "dropoff": max(0, prior - visitors),
                        "unreachable": bool(index and not visitors and prior)})
        previous = matches
    return sorted(results, key=lambda row: (row["dropoff"], row["name"]))


def percent_text(value):
    return (str(value).rstrip("0").rstrip(".") if isinstance(value, float) else str(value)) + "%"
