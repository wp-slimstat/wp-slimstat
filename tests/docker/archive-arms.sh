#!/usr/bin/env bash
# tests/docker/archive-arms.sh <dest-dir> [src-artifacts-dir]
#
# Copy a two-arm run's artifacts OUT of the mutable cell directory, and refuse to do it unless
# the copy actually holds two arms.
#
# The artifact directory is rewritten in place by every run. Archiving it by hand means copying
# from a path whose contents can change between the run and the copy, with nothing recording
# what was copied — and that is not a hypothetical: S1's first evidence archive held the NEW arm
# twice under the names `before.json` and `after.json` while the run log beside it reported a
# two-arm verdict (PITFALLS 60). Byte-identical files are exactly what a real comparison must
# never produce, so the archive was vacuous in the shape PITFALLS 44 names, and it was the
# artifact a phase closure rested on.
#
# So the copy asserts what it claims. `_arm_fingerprint` hashes the shipped PHP of each arm:
# two arms means two fingerprints. Under SLIMSTAT_NULL_CONTROL=1 the run deliberately puts the
# SAME ref on both sides, so there the assertion inverts — identical is the pass, and differing
# fingerprints mean the null control was not null.
#
# compare-answers.sh:242 already runs this same comparison as a run-time CONTROL, and it is
# deliberately repeated here rather than reused. That control PASSED on the very run whose
# archive was wrong: it establishes that the RUN had two arms, which is a different claim from
# what a later hand-copy produced. Two assertions at two moments, and the second is the one
# that would have caught this.
#
# Files are named by ARM IDENTITY (version + fingerprint prefix) behind a positional `arm-N`,
# not by before/after: "before" is a position in a command line and survives no move, and
# "old"/"new" would be a claim this script cannot hold — under a null control there is no old
# and no new, both arms being the same code. SHA256SUMS is written last; re-verify with
# `shasum -a 256 -c SHA256SUMS` before citing any file.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

DEST="${1:?dest dir}"
SRC="${2:-$WORK_ROOT/answers/answers/artifacts}"

[ -d "$SRC" ] || { err "no artifacts at $SRC"; exit 1; }
for f in before.json after.json run.json; do
  [ -f "$SRC/$f" ] || { err "$SRC/$f is missing — the run did not finish, and half an archive is worse than none"; exit 1; }
done

# INTENT IS READ FROM THE RUN, NEVER FROM THIS PROCESS'S ENVIRONMENT. The first version took
# it from $SLIMSTAT_NULL_CONTROL, which is a different process's variable read later by hand —
# and a stale `export SLIMSTAT_NULL_CONTROL=1`, entirely plausible in a shell that just ran a
# null control, flipped the one assertion this script exists to make into a PASS. Demonstrated
# against the literal PITFALLS 60 artifact: one arm copied twice, "[PASS] null control", exit 0.
# A guard whose polarity is set by ambient shell state is not a guard.
NULL_CONTROL="$(python3 -c 'import json,sys; print(1 if json.load(open(sys.argv[1])).get("null_control") in (1,"1",True) else 0)' "$SRC/run.json")" \
  || { err "could not read null_control from $SRC/run.json"; exit 1; }
# Never write into a directory that already holds an archive: a superseded run and its
# replacement sharing one directory is how the two get cited as if they were one.
[ -e "$DEST/SHA256SUMS" ] && { err "$DEST already holds an archive — supersede it explicitly, do not merge into it"; exit 1; }

# Identity comes from the answers document itself, not from the caller's argument order.
IDENT="$(python3 - "$SRC" <<'PY'
import json, sys
src = sys.argv[1]
out = []
for f in ('before', 'after'):
    d = json.load(open(f'{src}/{f}.json'))
    ver, fp = d.get('_arm_version'), d.get('_arm_fingerprint')
    if not ver or not fp:
        sys.exit(f'{f}.json carries no _arm_version/_arm_fingerprint')
    out += [str(ver), str(fp)]
print(' '.join(out))
PY
)" || { err "could not read _arm_version/_arm_fingerprint — an archive that cannot name its arms cannot be cited"; exit 1; }
# The intermediate is load-bearing, not clutter: `read … < <(python3 …)` and
# `read … <<<"$(python3 …)"` BOTH swallow python's exit status — read returns 0 and the script
# sails past a broken identity block. Only assigning first catches it.
read -r ARM1_VER ARM1_FP ARM2_VER ARM2_FP <<<"$IDENT"

# Positional labels, like measure-arms.sh: an index makes no claim it cannot hold.
echo "  arm 1 ('before'): version $ARM1_VER  fingerprint $ARM1_FP"
echo "  arm 2 ('after') : version $ARM2_VER  fingerprint $ARM2_FP"

if [ "$NULL_CONTROL" = "1" ]; then
  [ "$ARM1_FP" = "$ARM2_FP" ] || {
    err "NULL CONTROL, but the two arms hash differently ($ARM1_FP vs $ARM2_FP) — the control was not null"
    exit 1
  }
  echo "  [PASS] null control: both arms are the same code, as intended"
else
  [ "$ARM1_FP" != "$ARM2_FP" ] || {
    err "the two arms have the SAME fingerprint ($ARM1_FP) — this archive holds one arm twice, not a comparison"
    exit 1
  }
  echo "  [PASS] the archive holds two genuinely different arms"
fi

mkdir -p "$DEST" || exit 1
# `arm-$n` is what keeps the two files apart. Under a null control both arms carry the same
# version AND the same fingerprint — the check above just asserted it — so neither of those
# fields separates anything there. They are in the name for IDENTITY; the index is for
# SEPARATION. Trim the index for brevity and a null-control archive silently halves, which is
# PITFALLS 60 again in the script written to prevent it.
archive_arm() { # <side> <n> <ver> <fp>
  local side="$1" n="$2" ver="$3" fp="$4" sfx src_file
  for sfx in '' caps timing; do          # '' is the answers document, which has no -kind suffix
    src_file="$SRC/$side${sfx:+-$sfx}.json"
    [ -f "$src_file" ] || continue
    cp "$src_file" "$DEST/arm-$n-$ver-${fp:0:8}.${sfx:-answers}.json" || return 1
  done
}
archive_arm before 1 "$ARM1_VER" "$ARM1_FP" || exit 1
archive_arm after  2 "$ARM2_VER" "$ARM2_FP" || exit 1
# run.json travels WITH the evidence. Without it the archive records which code ran and not
# which run ran, and two runs of one ref pair produce byte-identical filenames.
cp "$SRC/run.json" "$DEST/run.json" || exit 1

( cd "$DEST" && shasum -a 256 ./*.json > SHA256SUMS ) || exit 1

# The CONTENT backstop, on the digests that were already being computed and never read: the
# first version wrote them to disk and then only counted the lines.
#
# It inverts with the mode for the same reason the fingerprint check does, and getting this
# wrong is instructive enough to record. A first draft called this "mode-independent" and
# failed on ANY shared digest — which would have rejected every legitimate null control, where
# two passes of one arm producing byte-identical answers IS the control passing. "Identical is
# always wrong" is only true when the run claimed the arms differ.
DUPES="$(awk '{print $1}' "$DEST/SHA256SUMS" | sort | uniq -d)"
if [ "$NULL_CONTROL" = "1" ]; then
  if [ -n "$DUPES" ]; then
    echo "  [PASS] null control: the two arms produced byte-identical artifacts, as intended"
  else
    err "NULL CONTROL, but no two archived files match — same code, two different answers"
    exit 1
  fi
else
  [ -z "$DUPES" ] || {
    err "two archived files share a digest — this archive holds one file twice, not a comparison:"
    awk -v d="$DUPES" 'index(d, $1)' "$DEST/SHA256SUMS" >&2
    rm -f "$DEST/SHA256SUMS"
    exit 1
  }
fi
# Count the MANIFEST, not the directory. The sentence claims the files are fixed by
# SHA256SUMS, so the number has to come from SHA256SUMS — a fresh `ls` of $DEST would report
# a file the checksum pass had missed as though it were covered, which is this script's own
# subject matter one level down.
log "archived $(( $(wc -l < "$DEST/SHA256SUMS") )) files to $DEST, fixed by SHA256SUMS"
