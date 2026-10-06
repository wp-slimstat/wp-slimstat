#!/usr/bin/env python3
"""Structured validation for tests/docker/seal.sh."""
from __future__ import annotations

import hashlib
import json
import math
import os
import sys
from datetime import datetime, timezone
from pathlib import Path


def fairness(bits: str) -> int:
    if not bits or set(bits) - {"0", "1"}:
        print("FLIP FAIRNESS: invalid assignment sequence", file=sys.stderr)
        return 1
    n, k = len(bits), bits.count("0")
    edge = max(k, n - k)
    p = min(1.0, 2 * sum(math.comb(n, i) for i in range(edge, n + 1)) / (2**n))
    runs = 1 + sum(a != b for a, b in zip(bits, bits[1:]))
    mean = 2 * k * (n - k) / n + 1
    variance = (
        2 * k * (n - k) * (2 * k * (n - k) - n) / (n * n * (n - 1))
        if n > 1
        else 0
    )
    z = abs(runs - mean) / math.sqrt(variance) if variance > 0 else math.inf
    t1, t2 = p < 0.01, z > 2.576
    z_text = "inf" if math.isinf(z) else f"{z:.2f}"
    print(
        f"FLIP FAIRNESS: T1 {'FAIL' if t1 else 'PASS'} k={k} of N={n} "
        f"(two-tailed p={p:.4f}, threshold p<0.01) · "
        f"T2 {'FAIL' if t2 else 'PASS'} R={runs}, z={z_text}"
    )
    return 1 if t1 or t2 else 0


def refuse(number: int, message: str) -> int:
    print(f"SEAL REFUSED: P{number} unmet — {message}", file=sys.stderr)
    return 4


# load() and manifest_digest() live in seal_common so that the SIDE THAT WRITES packet_sha256
# (file-reports.py) and the side that reads it back and refuses on mismatch (this file) cannot
# drift apart. See seal_common.py's header for what four separate spellings had already cost.
from seal_common import load, manifest_digest  # noqa: E402


def validate_structured(run: Path) -> tuple[int, str]:
    try:
        mapping = load(run / ".sealed/mapping.json")
        flip = load(run / "flip.json")
    except Exception:
        return 2, "flip.json or mapping.json does not parse"
    entropy = mapping.get("entropy_hex", "")
    if len(entropy) != 64:
        return 2, "entropy_hex is not 32 bytes"
    commitment = hashlib.sha256(entropy.encode()).hexdigest()
    if commitment != flip.get("commitment"):
        return 2, "sha256(entropy_hex) does not match the commitment published in flip.json"
    bit = int(entropy[:2], 16) & 1
    if bit != mapping.get("b"):
        return 2, "the recorded assignment bit does not derive from entropy_hex"
    expected = (
        (mapping["ref_a"], mapping["ref_b"])
        if bit == 0
        else (mapping["ref_b"], mapping["ref_a"])
    )
    if expected != (mapping.get("arm-1"), mapping.get("arm-2")):
        return 2, "arm assignment does not match ENTROPY_V1"

    for arm in ("arm-1", "arm-2"):
        path = run / f"adjudication/{arm}.report.json"
        if not path.is_file():
            return 4, f"packet/{arm} has no filed report at adjudication/{arm}.report.json"
        try:
            report = load(path)
        except Exception:
            return 4, f"adjudication/{arm}.report.json does not parse"
        if report.get("packet_sha256") != manifest_digest(run, arm):
            return 4, f"adjudication/{arm}.report.json attests the wrong packet digest"
        if report.get("arm") != arm or not isinstance(report.get("findings"), list):
            return 4, f"adjudication/{arm}.report.json has an invalid arm or findings"
        other = "arm-2" if arm == "arm-1" else "arm-1"
        if other in json.dumps(report):
            return 4, f"adjudication/{arm}.report.json mentions the other arm"

    comparator_path = run / "adjudication/comparator.report.json"
    if not comparator_path.is_file():
        return 5, "comparator.report.json is absent"
    try:
        comparator = load(comparator_path)
    except Exception:
        return 5, "comparator.report.json does not parse"
    if comparator.get("saw_mapping") is not False:
        return 5, "comparator.report.json reports saw_mapping=true"
    for arm in ("arm-1", "arm-2"):
        if comparator.get(arm, {}).get("packet_sha256") != manifest_digest(run, arm):
            return 5, f"comparator attests the wrong digest for {arm}"

    try:
        controls = load(run / "controls.json")
    except Exception:
        return 6, "controls.json is absent or invalid"
    required = [
        "arms_identical" if flip.get("null_control") else "arms_differ",
        "corpus_nontrivial",
        "window_is_strict_subset",
        "cardinality_past_cliff",
        "no_list_report_empty",
        "packet_scrub_clean",
        "flip_commitment_verified",
        "mapping_mode_0600",
        "names_neutral",
    ]
    for control_id in required:
        rows = [row for row in controls if row.get("id") == control_id]
        if len(rows) != 1:
            return 6, f"control '{control_id}' is absent from controls.json"
        if rows[0].get("status") != "PASS":
            return 6, f"control '{control_id}' status={rows[0].get('status')}"
    return 0, "PASS"


def reveal(run: Path) -> None:
    mapping = load(run / ".sealed/mapping.json")
    now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    (run / "flip-reveal.json").write_text(
        json.dumps(
            {
                "algorithm": "ENTROPY_V1",
                "entropy_hex": mapping["entropy_hex"],
                "b": mapping["b"],
            },
            sort_keys=True,
        )
        + "\n",
        encoding="utf-8",
    )
    (run / "unseal.json").write_text(
        json.dumps(
            {
                "unsealed_at": now,
                "uid": os.getuid(),
                "arm-1": mapping["arm-1"],
                "arm-2": mapping["arm-2"],
                "preconditions": {f"P{i}": "PASS" for i in range(1, 8)},
            },
            sort_keys=True,
        )
        + "\n",
        encoding="utf-8",
    )
    os.chmod(run / ".sealed/mapping.json", 0o644)
    os.chmod(run / ".sealed", 0o755)
    print(f"arm-1={mapping['arm-1']}\narm-2={mapping['arm-2']}")


def main() -> int:
    if len(sys.argv) == 3 and sys.argv[1] == "fairness":
        return fairness(sys.argv[2])
    if len(sys.argv) == 3 and sys.argv[1] == "validate":
        number, message = validate_structured(Path(sys.argv[2]))
        if number:
            return refuse(number, message)
        return 0
    if len(sys.argv) == 3 and sys.argv[1] == "reveal":
        reveal(Path(sys.argv[2]))
        return 0
    print("usage: seal-tool.py fairness <bits> | validate <run> | reveal <run>", file=sys.stderr)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
