"""The two packet primitives, defined once.

`manifest_digest` is what ties a filed adjudication report to the bytes it read: `file-reports.py`
WRITES it into each report and `seal-tool.py` READS it back and refuses the unseal when they
disagree. Producer and consumer had separate implementations, `seal-negative-suite.py` had a
third, and `seal-lib.sh:seal_arm_manifest_digest` had a fourth that was not even the same rule —
it anchored the match to awk field 2 where every Python copy tests substring-anywhere-in-line, and
it had zero callers. Four spellings of one digest, on both ends of a check whose whole job is to
detect disagreement; the awk one had already drifted. Extracted at the fourth copy, which is three
later than this codebase's own rule says (`lib.sh`: *"Extracted at the FOURTH private copy, which
was also the first to drift"*), and the orphan is deleted rather than converted.

The underscore in the filename is load-bearing: `seal-tool.py` and the rest are hyphenated and
therefore un-importable, which is why every cross-file use in this directory goes through
`subprocess`. This module is importable so the three Python callers can share code without any of
those call sites changing.

Callers in `tests/docker/canary/` are one directory down and add their parent to `sys.path`; that
is deliberate and cheap, and beats a fifth copy.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path


def load(path: Path):
    """Read a JSON artifact. Raises rather than defaulting — a seal artifact that will not parse
    is a refusal, never an empty dict."""
    with Path(path).open(encoding="utf-8") as handle:
        return json.load(handle)


def manifest_digest(run: Path, arm: str) -> str:
    """sha256 of the packet manifest's lines for ONE arm, in manifest order.

    Over the raw lines including their newlines, so the digest covers the manifest exactly as
    `build-packet.py` wrote it. Substring match on `packet/<arm>/`, matching every existing Python
    caller; do not "tighten" it to a field or a prefix without changing all of them together, or
    the producer and the consumer stop agreeing and the failure surfaces as a seal refusal several
    steps away from the edit.
    """
    lines = (Path(run) / "packet/MANIFEST.sha256").read_bytes().splitlines(keepends=True)
    body = b"".join(line for line in lines if f"packet/{arm}/".encode() in line)
    return hashlib.sha256(body).hexdigest()
