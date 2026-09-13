"""Independent pageview-chart semantics over exported timestamps and IP values."""

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


def pageviews_chart(rows, capture_end, duration_days, granularity, start_of_week=1):
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

    datasets = {period: {"v1": [0] * len(labels), "v2": [set() for _ in labels]}
                for period in bounds}
    totals = {period: {"v1": 0, "v2": set()} for period in bounds}
    for index, row in enumerate(rows):
        if not isinstance(row, dict) or "dt" not in row or "ip" not in row:
            raise ValueError("chart row %d must contain dt and ip" % index)
        if type(row["dt"]) is not int:
            raise ValueError("chart row %d dt must be an integer" % index)
        period = next((name for name, (low, high) in bounds.items() if low <= row["dt"] <= high), None)
        if period is None or row["ip"] is None:
            continue
        totals[period]["v1"] += 1
        totals[period]["v2"].add(row["ip"])
        if granularity == "DAY":
            offset = (_date(row["dt"]).date() - _date(bounds[period][0]).date()).days
        else:
            offset = (_week_start(row["dt"], start_of_week).date()
                      - _week_start(bounds[period][0], start_of_week).date()).days // 7
        if 0 <= offset < len(labels):
            datasets[period]["v1"][offset] += 1
            datasets[period]["v2"][offset].add(row["ip"])

    previous_start = bounds["previous"][0]
    prev_labels = [(_date(previous_start) + timedelta(days=i * (1 if granularity == "DAY" else 7)))
                   .strftime("%Y/%m/%d") for i in range(len(labels))]
    values = {period: {"v1": data["v1"], "v2": [len(values) for values in data["v2"]]}
              for period, data in datasets.items()}
    total_rows = [{"v1": totals[period]["v1"], "v2": len(totals[period]["v2"]), "period": period}
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
