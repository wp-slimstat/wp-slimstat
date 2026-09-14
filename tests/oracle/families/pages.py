"""Independent models for grouped page report answers."""

def _ascii_ci(value):
    if value is None:
        return None
    raw = value if isinstance(value, bytes) else str(value).encode("ascii")
    if any(byte > 127 for byte in raw):
        raise ValueError("page report cannot model non-ASCII collation semantics")
    return raw.rstrip(b" ").lower()


def _text(value):
    if value is None:
        return None
    raw = value if isinstance(value, bytes) else str(value).encode("ascii")
    if any(byte > 127 for byte in raw):
        raise ValueError("page report cannot model non-ASCII output")
    return raw.decode("ascii")


def _canonical(rows):
    # Capture uses ksort(row) followed by lexicographic json_encode(row).
    escapes = {'"': '\\"', '\\': '\\\\', '/': '\\/', '\b': '\\b', '\f': '\\f',
               '\n': '\\n', '\r': '\\r', '\t': '\\t'}

    def scalar(value):
        if value is None:
            return "null"
        return '"' + ''.join(escapes.get(char, char if ord(char) >= 32 else '\\u%04x' % ord(char))
                             for char in value) + '"'

    def encoded(row):
        return "{" + ",".join(scalar(key) + ":" + scalar(row[key]) for key in sorted(row)) + "}"
    return sorted(rows, key=encoded)


def _window(rows, start, end, required):
    if type(start) is not int or type(end) is not int or start > end:
        raise ValueError("page report requires an ordered integer window")
    answer = []
    for index, row in enumerate(rows):
        if not isinstance(row, dict) or any(name not in row for name in required):
            raise ValueError("page row %d lacks a consumed field" % index)
        if start <= row["dt"] <= end:
            answer.append(row)
    return answer


def _group_resources(resources):
    grouped = {}
    displays = {}
    for resource in resources:
        key = _ascii_ci(resource)
        display = _text(resource)
        if key in displays and displays[key] != display:
            raise ValueError("page report has an ambiguous collation-equivalent resource")
        displays[key] = display
        grouped[key] = grouped.get(key, 0) + 1
    return grouped, displays


def recent_downloads(rows, start, end, limit):
    """Group download hits by resource and retain each resource's latest timestamp."""
    if type(limit) is not int or limit < 1:
        raise ValueError("page report limit must be a positive integer")
    downloads = []
    for row in _window(rows, start, end, ("resource", "content_type", "dt")):
        if _ascii_ci(row["content_type"]) == b"download":
            downloads.append(row)
    counts, displays = _group_resources(row["resource"] for row in downloads)
    latest = {}
    for row in downloads:
        key = _ascii_ci(row["resource"])
        latest[key] = max(latest.get(key, row["dt"]), row["dt"])
    ranked = sorted(counts, key=lambda key: (-latest[key], key is not None, key or b""))[:limit]
    return _canonical([{"resource": displays[key], "counthits": str(counts[key]),
                        "dt": str(latest[key])} for key in ranked])


def visit_boundary_pages(rows, start, end, boundary, limit):
    """Choose the first/last row ID per visit, then count those pages."""
    if boundary not in ("MIN", "MAX") or type(limit) is not int or limit < 1:
        raise ValueError("invalid visit-boundary page contract")
    selected = {}
    by_id = {}
    for row in _window(rows, start, end, ("id", "visit_id", "resource", "dt")):
        if type(row["id"]) is not int:
            raise ValueError("visit-boundary row id is not an integer")
        by_id[row["id"]] = row
        current = selected.get(row["visit_id"])
        if current is None or (boundary == "MIN" and row["id"] < current) \
                or (boundary == "MAX" and row["id"] > current):
            selected[row["visit_id"]] = row["id"]
    counts, displays = _group_resources(by_id[row_id]["resource"] for row_id in selected.values())
    ranked = sorted(counts, key=lambda key: (-counts[key], key is not None, key or b""))[:limit]
    return _canonical([{"resource": displays[key], "counthits": str(counts[key])} for key in ranked])
