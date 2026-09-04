#!/usr/bin/env bash
# Compatibility entry point for Docker cells. Pro's build/build-dist.sh owns the only scoper
# recipe; this wrapper resolves a commit and copies that shipped artifact to the stable path the
# existing cells consume.
set -euo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

PRO_REPO="$(cd "$PLUGIN_SRC/../wp-slimstat-pro" && pwd)"
REF="${PRO_REF_OVERRIDE:-HEAD}"
FULL=$(git -C "$PRO_REPO" rev-parse "$REF^{commit}")
SHA=${FULL:0:8}
OUT="${PRO_ZIP_OUT:-$HARNESS_DIR/build/wp-slimstat-pro.zip}"
REF_STAMP="$OUT.ref"
HASH_STAMP="$OUT.sha256"

[ -x "$PRO_REPO/build/build-dist.sh" ] || { err "Pro shipped builder is absent"; exit 1; }
mkdir -p "$(dirname "$OUT")"
if [ -f "$OUT" ] && [ -f "$REF_STAMP" ] && [ -f "$HASH_STAMP" ] \
   && [ "$(cat "$REF_STAMP")" = "$FULL" ] \
   && [ "$(shasum -a 256 "$OUT" | cut -d' ' -f1)" = "$(cat "$HASH_STAMP")" ]; then
  log "Pro ZIP up-to-date for $SHA: $OUT"
  exit 0
fi

# The builder decides the directory and this script reads what it decided — one variable,
# never a second copy of the recipe (PITFALLS 115). /tmp is /private/tmp on macOS.
export WPSS_BUILD_DIR="${WPSS_BUILD_DIR:-/tmp/wpss-pro-$SHA}"
if [ -n "${PRO_BUILD_LOG:-}" ]; then
  "$PRO_REPO/build/build-dist.sh" --ref "$FULL" --out "$HARNESS_DIR/build" \
    > "$PRO_BUILD_LOG" 2>&1
else
  "$PRO_REPO/build/build-dist.sh" --ref "$FULL" --out "$HARNESS_DIR/build"
fi
ENV_FILE="${WPSS_BUILD_DIR}/env.sh"
[ -f "$ENV_FILE" ] || { err "Pro builder did not write $ENV_FILE"; exit 1; }
# shellcheck disable=SC1090
source "$ENV_FILE"
[ -n "${ZIP:-}" ] && [ -f "$ZIP" ] || { err "Pro builder produced no ZIP"; exit 1; }
cp "$ZIP" "$OUT"
unzip -tqq "$OUT" || { err "Pro ZIP failed its CRC check"; exit 1; }
printf '%s' "$FULL" > "$REF_STAMP"
shasum -a 256 "$OUT" | cut -d' ' -f1 > "$HASH_STAMP"
log "Pro shipped ZIP ready: $OUT ($(du -h "$OUT" | cut -f1), $SHA)"
