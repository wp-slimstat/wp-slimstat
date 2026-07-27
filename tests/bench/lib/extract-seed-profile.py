#!/usr/bin/env python3
"""
Extract a seed profile from a real wp_slim_stats dump.

The benchmark is only worth running if the seeded data resembles real traffic.
Synthetic uniform data makes every index look good: uniform URLs give perfect
selectivity, uniform visit_ids give a bounce rate of zero, and a table with no
bots is 27.7% smaller than the real thing. Distributions here are measured, not
invented.

Usage:
    python3 tests/bench/lib/extract-seed-profile.py <dump.sql> [-o seed-profile.json]

Privacy: the profile is committed to the repo, so it carries NO personal data.
Dropped entirely: ip, other_ip, username, email, fingerprint, city, searchterms.
Referers are reduced to their host. Resource query strings are reduced to their
parameter *names* — real dumps carry values like ?utm_source=<person> and the
shape is what matters for cardinality, not the value.
"""

import argparse
import collections
import json
import pathlib
import re
import sys
from urllib.parse import urlsplit

EXTRACTOR_VERSION = 2

COLS = (
    "id ip other_ip username email country location city referer resource searchterms notes "
    "visit_id server_latency page_performance browser browser_version browser_type platform "
    "language fingerprint user_agent resolution screen_width screen_height content_type "
    "category author content_id outbound_resource tz_offset dt_out dt"
).split()

# Columns that may never reach the committed profile.
PII_COLS = {"ip", "other_ip", "username", "email", "fingerprint", "city", "searchterms", "notes"}

TOP_N = {
    "resource": 300,
    "user_agent": 150,
    "referer": 100,
    "resolution": 50,
    "country": 120,
    "browser": 60,
    "browser_version": 60,
    "platform": 25,
    "language": 40,
    "content_type": 30,
}


def split_tuple(s: str):
    """Split one SQL VALUES tuple on commas that are not inside quotes."""
    out, cur, quoted, esc = [], [], False, False
    for ch in s:
        if esc:
            cur.append(ch)
            esc = False
            continue
        if ch == "\\":
            cur.append(ch)
            esc = True
            continue
        if ch == "'":
            quoted = not quoted
            cur.append(ch)
            continue
        if ch == "," and not quoted:
            out.append("".join(cur))
            cur = []
            continue
        cur.append(ch)
    out.append("".join(cur))
    return out


def cell(raw: str):
    raw = raw.strip()
    return None if raw == "NULL" else raw.strip("'")


def scrub_resource(value: str) -> str:
    """
    Keep the path shape; drop identifiers.

    Query values become `x` (real dumps carry ?utm_source=<person>), and numeric
    path segments become `N` (order IDs, user IDs, post IDs). Cardinality is not
    lost by this: the true distinct count is recorded separately in `distinct`,
    and the seeder synthesises filler to reach it.
    """
    path, sep, query = value.partition("?")
    path = re.sub(r"/\d{2,}(?=/|$)", "/N", path)
    if not sep:
        return path[:255]
    names = sorted({p.partition("=")[0] for p in query.split("&") if p})
    return (path + ("?" + "&".join(f"{n}=x" for n in names if n) if names else ""))[:255]


def scrub_referer(value: str) -> str:
    """Reduce to scheme://host — query strings can carry search terms."""
    try:
        parts = urlsplit(value)
        if parts.scheme and parts.netloc:
            return f"{parts.scheme}://{parts.netloc}/"
    except ValueError:
        pass
    return re.sub(r"[?#].*$", "", value)[:120]


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("dump")
    ap.add_argument("-o", "--out", default=None)
    ap.add_argument("--table", default="wp_slim_stats")
    ap.add_argument("--source-label", default="maintainers' own site (permission on file)",
                    help="who the dump came from — recorded for provenance, never PII")
    ap.add_argument("--extracted-at", default="unknown",
                    help="ISO date of extraction; pass $(date -u +%%Y-%%m-%%d)")
    args = ap.parse_args()

    dump = pathlib.Path(args.dump)
    if not dump.exists():
        print(f"dump not found: {dump}", file=sys.stderr)
        return 2

    marker = f"INSERT INTO `{args.table}` VALUES"
    counters = collections.defaultdict(collections.Counter)
    # True cardinality, measured before scrubbing. Scrubbing collapses
    # /order/8708/ and /order/9142/ into one value, which would understate
    # distinctness — and index selectivity is exactly what that governs. The
    # seeder synthesises filler to reach these counts.
    distinct_raw = collections.defaultdict(set)
    nulls = collections.Counter()
    visit_pv = collections.Counter()
    rows = 0
    in_block = False

    with dump.open(encoding="utf-8", errors="replace") as fh:
        for line in fh:
            if line.startswith(marker):
                in_block = True
                continue
            if line.startswith("INSERT INTO `"):
                in_block = False
            if not in_block:
                continue
            text = line.strip().rstrip(";").rstrip(",")
            if not (text.startswith("(") and text.endswith(")")):
                continue
            values = split_tuple(text[1:-1])
            if len(values) != len(COLS):
                continue

            rows += 1
            row = {k: cell(v) for k, v in zip(COLS, values)}

            for col, value in row.items():
                if value is None:
                    nulls[col] += 1
                    continue
                if col in PII_COLS or col not in TOP_N:
                    continue
                distinct_raw[col].add(value)
                if col == "resource":
                    value = scrub_resource(value)
                elif col == "referer":
                    value = scrub_referer(value)
                counters[col][value] += 1

            counters["browser_type"][row["browser_type"] or "0"] += 1
            if row["visit_id"] and row["visit_id"] != "0":
                visit_pv[row["visit_id"]] += 1

    if rows == 0:
        print(f"no rows parsed for {args.table} — wrong table name?", file=sys.stderr)
        return 2

    pv_hist = collections.Counter(visit_pv.values())
    visits = sum(pv_hist.values())

    profile = {
        "_comment": "Generated by tests/bench/lib/extract-seed-profile.py. No personal data: "
                    "ip/username/email/fingerprint/city/searchterms/notes are never emitted, "
                    "referers are reduced to host, resource query values to parameter names.",
        # Provenance. Without it nobody can tell whether the profile has gone
        # stale relative to real traffic, and a stale profile silently
        # invalidates every benchmark built on it.
        "extractor_version": EXTRACTOR_VERSION,
        "extracted_at": args.extracted_at,
        "source_label": args.source_label,
        "source_rows": rows,
        "source_visits": visits,
        "source_window": {
            "note": "dt range of the source dump; see source_label for the site",
        },
        "null_rates": {
            c: round(nulls[c] / rows, 4)
            for c in sorted(nulls)
            # fingerprint's rate drives the seeder; the rest of PII_COLS is excluded.
            if c not in (PII_COLS - {"fingerprint"})
        },
        "browser_type_mix": {
            k: round(v / rows, 4) for k, v in sorted(counters["browser_type"].items())
        },
        # Weighted [value, count] pairs. The seeder samples proportionally, so a
        # head-heavy list reproduces the real skew without storing every row.
        "weighted": {
            col: [[v, n] for v, n in counters[col].most_common(TOP_N[col])]
            for col in TOP_N
            if counters[col]
        },
        # Distinct counts including the tail the weighted list truncates — the
        # seeder synthesises filler to reach this cardinality, which is what
        # index selectivity actually depends on.
        "distinct": {col: len(distinct_raw[col]) for col in TOP_N if counters[col]},
        # [pageviews_in_visit, share_of_visits] — 92.9% of real visits are a
        # single pageview; a uniform seeder would wreck bounce/exit reports.
        # Kept in full, deliberately. An earlier version dropped buckets under
        # 0.01% of visits, which looked harmless — but the long tail is bot
        # sessions hitting hundreds of pages, and it carried 0.57 of the 1.79
        # mean. Truncating it seeded 1.23 pageviews/visit instead, which would
        # have quietly changed every bounce, exit and pages-per-visit report.
        "pageviews_per_visit": [
            [pv, round(n / visits, 7)] for pv, n in sorted(pv_hist.items())
        ],
        "mean_pageviews_per_visit": round(sum(visit_pv.values()) / visits, 3) if visits else 0,
    }

    leaked = PII_COLS & set(profile["weighted"])
    if leaked:
        print(f"refusing to write: PII columns present {leaked}", file=sys.stderr)
        return 2

    out = args.out or str(pathlib.Path(__file__).resolve().parent.parent / "seed-profile.json")
    pathlib.Path(out).write_text(json.dumps(profile, indent=2, ensure_ascii=False) + "\n")
    print(f"wrote {out}")
    print(f"  rows={rows} visits={visits} mean_pv/visit={profile['mean_pageviews_per_visit']}")
    for col in sorted(profile["distinct"]):
        print(f"  {col:16s} distinct={profile['distinct'][col]:5d} "
              f"kept={len(profile['weighted'].get(col, []))}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
