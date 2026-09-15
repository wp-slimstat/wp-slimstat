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
        if isinstance(value, int) and not isinstance(value, bool):
            return str(value)
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


def _group_values(values):
    grouped = {}
    displays = {}
    for value in values:
        key = _ascii_ci(value)
        display = _text(value)
        if key in displays and displays[key] != display:
            raise ValueError("page report has an ambiguous collation-equivalent value")
        displays[key] = display
        grouped[key] = grouped.get(key, 0) + 1
    return grouped, displays


def grouped_values(rows, start, end, dimension, limit, content_type=None, contains=False,
                   require_nonempty=False, trim_slash=False, recent=False,
                   dimension_prefix=None, filter_join="and"):
    """Filter, transform and group a scalar report dimension."""
    if type(limit) is not int or limit < 1:
        raise ValueError("page report limit must be a positive integer")
    required = [dimension, "dt"] + (["content_type"] if content_type is not None else [])
    selected = []
    for row in _window(rows, start, end, required):
        matches_content = True
        if content_type is not None:
            actual, expected = _ascii_ci(row["content_type"]), content_type.encode("ascii")
            matches_content = actual is not None and (expected in actual if contains else actual == expected)
        value = row[dimension]
        matches_dimension = (dimension_prefix is None or value is not None
                             and _ascii_ci(value).startswith(dimension_prefix.encode("ascii")))
        if not ((matches_content or matches_dimension) if filter_join == "or"
                else (matches_content and matches_dimension)):
            continue
        if require_nonempty and (value is None or _ascii_ci(value) == b""):
            continue
        if trim_slash and value is not None:
            value = (value.rstrip(b"/") if isinstance(value, bytes) else value.rstrip("/"))
        selected.append((value, row["dt"]))
    counts, displays = _group_values(value for value, _dt in selected)
    latest = {}
    for value, dt in selected:
        key = _ascii_ci(value)
        latest[key] = max(latest.get(key, dt), dt)
    ranked = sorted(counts, key=lambda key: (
        -(latest[key] if recent else counts[key]), key is not None, key or b""))[:limit]
    answer = [{dimension: displays[key], "counthits": str(counts[key])} for key in ranked]
    if recent:
        for row, key in zip(answer, ranked):
            row["dt"] = str(latest[key])
    return _canonical(answer)


def grouped_dimensions(rows, start, end, dimensions, limit, filter_column, filter_value,
                       exclude=False):
    """Group a tuple of dimensions after an integer equality/inequality filter."""
    if not dimensions or type(limit) is not int or limit < 1:
        raise ValueError("invalid multi-dimension page contract")
    required = tuple(dimensions) + (filter_column, "dt")
    values = []
    for row in _window(rows, start, end, required):
        matched = row[filter_column] == filter_value
        if matched == exclude:
            continue
        display = tuple(_text(row[name]) for name in dimensions)
        key = tuple(_ascii_ci(row[name]) for name in dimensions)
        values.append((key, display))
    counts, displays = {}, {}
    for key, display in values:
        if key in displays and displays[key] != display:
            raise ValueError("page report has an ambiguous collation-equivalent tuple")
        displays[key] = display
        counts[key] = counts.get(key, 0) + 1
    order = lambda key: tuple((part is not None, part or b"") for part in key)
    ranked = sorted(counts, key=lambda key: (-counts[key], order(key)))[:limit]
    return _canonical([dict(zip(dimensions, displays[key]), counthits=str(counts[key]))
                       for key in ranked])


def filtered_recent(rows, start, end, dimension, limit, mode):
    """Return pinned recent feed/search rows with get_recent's projected columns."""
    required = (dimension, "resource", "content_type", "dt", "ip")
    selected = []
    for row in _window(rows, start, end, required):
        content = _ascii_ci(row["content_type"])
        value = row[dimension]
        if mode == "searches":
            matched = content is not None and b"search" in content \
                and value is not None and _ascii_ci(value) != b""
        elif mode == "feeds":
            resource = _ascii_ci(row["resource"])
            matched = ((resource is not None and any(item in resource for item in
                        (b"/feed", b"?feed=>", b"&feed=>")))
                       or content is not None and b"feed" in content)
        else:
            raise ValueError("unsupported recent page mode")
        if matched:
            selected.append(row)
    selected.sort(key=lambda row: -row["dt"])
    if len(selected) > limit and selected[limit - 1]["dt"] == selected[limit]["dt"]:
        raise ValueError("recent page LIMIT cuts through an unordered timestamp tie")
    answer = [{dimension: _text(row[dimension]), "dt": str(row["dt"]), "ip": _text(row["ip"])}
              for row in selected[:limit]]
    return _canonical(answer)


def recent_outbound(rows, start, end, limit):
    """Explode the latest raw outbound rows and aggregate URLs by their click timestamp."""
    selected = []
    for row in _window(rows, start, end, ("outbound_resource", "dt", "dt_out")):
        value = row["outbound_resource"]
        if value is not None and _ascii_ci(value) != b"":
            selected.append(row)
    selected.sort(key=lambda row: -row["dt"])
    if len(selected) > limit and selected[limit - 1]["dt"] == selected[limit]["dt"]:
        raise ValueError("outbound LIMIT cuts through an unordered timestamp tie")
    aggregate = {}
    for row in selected[:limit]:
        raw = row["outbound_resource"] if isinstance(row["outbound_resource"], bytes) \
            else row["outbound_resource"].encode("ascii")
        click_dt = row["dt_out"] if isinstance(row["dt_out"], int) and row["dt_out"] > 0 else row["dt"]
        for value in raw.split(b";;;"):
            if not value:
                continue
            text = _text(value)
            if text.lstrip("-").isdigit():
                raise ValueError("numeric outbound URL has PHP array-key semantics")
            item = aggregate.setdefault(text, {"counthits": 0, "dt": 0})
            item["counthits"] += 1
            item["dt"] = max(item["dt"], click_dt)
    return _canonical([{"outbound_resource": url, "counthits": item["counthits"], "dt": item["dt"]}
                       for url, item in aggregate.items()])


def recent_downloads(rows, start, end, limit):
    """Group download hits by resource and retain each resource's latest timestamp."""
    return grouped_values(rows, start, end, "resource", limit, content_type="download", recent=True)


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
    counts, displays = _group_values(by_id[row_id]["resource"] for row_id in selected.values())
    ranked = sorted(counts, key=lambda key: (-counts[key], key is not None, key or b""))[:limit]
    return _canonical([{"resource": displays[key], "counthits": str(counts[key])} for key in ranked])
