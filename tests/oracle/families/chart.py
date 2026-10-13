"""Independent chart semantics over exported timestamps and metric values."""

from datetime import datetime, timedelta, timezone


DAY = 86400


def _date(timestamp):
    return datetime.fromtimestamp(timestamp, timezone.utc)


def _week_start(timestamp, start_of_week):
    day = _date(timestamp)
    # Python Monday=0; WordPress Sunday=0.
    wordpress_day = (day.weekday() + 1) % 7
    return day - timedelta(days=(wordpress_day - start_of_week) % 7,
                           hours=day.hour, minutes=day.minute,
                           seconds=day.second, microseconds=day.microsecond)


def _distinct(value, equality):
    if equality == "binary":
        return value
    raw = value if isinstance(value, bytes) else value.encode("ascii")
    if equality != "ascii_ci" or any(byte > 127 for byte in raw):
        raise ValueError("unsupported chart equality")
    return raw.rstrip(b" ").lower()


def _excluded(value, excluded):
    return any(value == item or (isinstance(value, bytes) and isinstance(item, str)
                                 and value == item.encode("utf-8")) for item in excluded)


def pageviews_chart(rows, capture_end, duration_days, granularity, start_of_week=1,
                    metric_column="ip", excluded=(), equality="binary",
                    distinct_v1=False, metric2_column=None, row_filter=None):
    """Model one chart's two series.

    v1 and v2 are two SQL aggregates over the same rows, and until slim_p3_01 every chart in
    the catalog spelled them over ONE column — COUNT(x) and COUNT(DISTINCT x) — so the model
    could skip a row for both series at once. Traffic Sources counts DISTINCT referer against
    DISTINCT ip, so the two series disagree about which rows they see: a row with a referer and
    no ip belongs to v1 alone. The accumulators are therefore separate, and with
    metric2_column=None v2 reads the same column v1 does, which reproduces the coupled skip
    exactly rather than approximating it.
    """
    if type(capture_end) is not int or capture_end < DAY:
        raise ValueError("chart capture end must be a positive integer timestamp")
    if type(duration_days) is not int or duration_days < 1:
        raise ValueError("chart duration must be a positive integer number of days")
    if granularity not in ("DAY", "WEEK"):
        raise ValueError("unsupported chart granularity")
    if type(start_of_week) is not int or not 0 <= start_of_week <= 6:
        raise ValueError("chart start_of_week must be 0..6")

    end = capture_end // DAY * DAY - 1
    start = end - duration_days * DAY + 1
    span = end - start
    bounds = {
        "current": (start, end),
        "previous": (start - span, end - span),
    }

    if granularity == "DAY":
        labels = [_date(start + i * DAY).strftime("'%Y/%m/%d'") for i in range(duration_days)]
    else:
        first = _date(start)
        labels = [first.strftime("'%Y/%m/%d'")]
        next_week = _week_start(start, start_of_week)
        if int(next_week.timestamp()) <= start:
            next_week += timedelta(days=7)
        while int(next_week.timestamp()) <= end:
            labels.append(next_week.strftime("'%Y/%m/%d'"))
            next_week += timedelta(days=7)

    second_column = metric2_column or metric_column
    empty_v1 = (lambda: set()) if distinct_v1 else (lambda: 0)
    datasets = {period: {"v1": [empty_v1() for _ in labels], "v2": [set() for _ in labels]}
                for period in bounds}
    totals = {period: {"v1": empty_v1(), "v2": set()} for period in bounds}
    for index, row in enumerate(rows):
        if not isinstance(row, dict) or "dt" not in row \
                or any(column not in row for column in (metric_column, second_column)):
            raise ValueError("chart row %d lacks a consumed field" % index)
        if type(row["dt"]) is not int:
            raise ValueError("chart row %d dt must be an integer" % index)
        period = next((name for name, (low, high) in bounds.items() if low <= row["dt"] <= high), None)
        if period is None or (row_filter is not None and not row_filter(row)):
            continue
        if granularity == "DAY":
            offset = (_date(row["dt"]).date() - _date(bounds[period][0]).date()).days
        else:
            offset = (_week_start(row["dt"], start_of_week).date()
                      - _week_start(bounds[period][0], start_of_week).date()).days // 7
        for series, column in (("v1", metric_column), ("v2", second_column)):
            metric = row[column]
            # COUNT(expr) and COUNT(DISTINCT expr) both skip NULL, per column, on their own.
            if metric is None or _excluded(metric, excluded):
                continue
            distinct = series == "v2" or distinct_v1
            value = _distinct(metric, equality) if distinct else 1
            if distinct:
                totals[period][series].add(value)
            else:
                totals[period][series] += value
            if 0 <= offset < len(labels):
                if distinct:
                    datasets[period][series][offset].add(value)
                else:
                    datasets[period][series][offset] += value

    previous_start = bounds["previous"][0]
    prev_labels = [(_date(previous_start) + timedelta(days=i * (1 if granularity == "DAY" else 7)))
                   .strftime("%Y/%m/%d") for i in range(len(labels))]
    def sized(bucket):
        return len(bucket) if isinstance(bucket, set) else bucket

    values = {period: {"v1": [sized(bucket) for bucket in data["v1"]],
                       "v2": [len(bucket) for bucket in data["v2"]]}
              for period, data in datasets.items()}
    total_rows = [{"v1": sized(totals[period]["v1"]), "v2": len(totals[period]["v2"]),
                   "period": period}
                  for period in ("current", "previous")]
    capture_day = capture_end // DAY * DAY
    today = (_week_start(end, start_of_week).strftime("%Y/%m/%d")
             if granularity == "WEEK" and _week_start(end, start_of_week) == _week_start(capture_day, start_of_week)
             else _date(capture_day).strftime("%Y/%m/%d"))
    return {
        "labels": labels,
        "totals": total_rows,
        "prev_labels": prev_labels,
        "datasets": values["current"],
        "datasets_prev": values["previous"],
        "today": today,
        "granularity": granularity,
    }
