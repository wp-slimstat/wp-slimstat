"""Independent models for captured summary reports."""

from collections import defaultdict
from decimal import Decimal, ROUND_HALF_UP


_DURATION_BUCKETS = (
    ("0 - 30 seconds", None, 30, 30),
    ("31 - 60 seconds", 30, 60, 60),
    ("1 - 3 minutes", 60, 180, 180),
    ("3 - 5 minutes", 180, 300, 300),
    ("5 - 7 minutes", 300, 420, 420),
    ("7 - 10 minutes", 420, 600, 600),
    ("More than 10 minutes", 600, None, 900),
)


def visit_duration(rows, start, end):
    if type(start) is not int or type(end) is not int or start > end:
        raise ValueError("visit-duration window must be inclusive integer bounds")

    visits = defaultdict(list)
    for index, row in enumerate(rows):
        if not isinstance(row, dict) or not all(
                column in row for column in ("visit_id", "browser_type", "dt", "dt_out")):
            raise ValueError("visit-duration row %d lacks a consumed column" % index)
        if row["visit_id"] is None or row["browser_type"] is None or row["dt"] is None:
            continue
        if row["visit_id"] > 0 and row["browser_type"] != 1 and start <= row["dt"] <= end:
            visits[row["visit_id"]].append(row)

    counts = [0] * len(_DURATION_BUCKETS)
    for rows_for_visit in visits.values():
        exits = [row["dt_out"] for row in rows_for_visit if row["dt_out"] is not None]
        if not exits:
            continue
        duration = max(max(row["dt"] for row in rows_for_visit), max(exits)) \
            - min(row["dt"] for row in rows_for_visit)
        for index, (_, low, high, _) in enumerate(_DURATION_BUCKETS):
            if (low is None or duration > low) and (high is None or duration <= high):
                counts[index] += 1
                break

    total = len(visits)
    result = []
    for (metric, _, _, weight), count in zip(_DURATION_BUCKETS, counts):
        percent = ("%.2f" % (Decimal(100 * count) / total).quantize(
            Decimal("0.01"), ROUND_HALF_UP)) if total else "0"
        result.append({"counthits": count, "details": "Hits: %d" % count,
                       "metric": metric, "value": percent + "%"})

    seconds = sum(bucket[3] * count for bucket, count in zip(_DURATION_BUCKETS, counts)) // total \
        if total else 0
    average = ("%02d:%02d:%02d" % (seconds // 3600, seconds // 60 % 60, seconds % 60)
               if seconds >= 3600 else "%02d:%02d" % (seconds // 60, seconds % 60)) \
        if total else "0:00"
    # Extended captures canonically order rows by their encoded object. Bucket
    # objects begin with counthits; the average object begins with details.
    result.sort(key=lambda row: (str(row["counthits"]), row["metric"]))
    result.append({"details": "", "metric": "Average Visit Duration", "value": average})
    return result
