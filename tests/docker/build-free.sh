#!/usr/bin/env bash
# Build the exact Free artifact an upgrade cell installs: committed files only, filtered by the
# exported .distignore, with the wp-slimstat/ slug at the ZIP root.
set -euo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

REF="${1:?free ref}"
FULL=$(git -C "$PLUGIN_SRC" rev-parse "$REF^{commit}")
SHA=${FULL:0:8}
OUT="${FREE_ZIP_OUT:-$HARNESS_DIR/build/wp-slimstat-$SHA.zip}"
BUILD="${WPSS_FREE_BUILD_DIR:-/tmp/wpss-free-$SHA}"  # /tmp, not /private/tmp: same dir on macOS, exists on Linux (PITFALLS 115)
REF_STAMP="$OUT.ref"
HASH_STAMP="$OUT.sha256"
DISTIGNORE="$BUILD/distignore"

mkdir -p "$(dirname "$OUT")"
CACHED=0
if [ -f "$OUT" ] && [ -f "$REF_STAMP" ] && [ -f "$HASH_STAMP" ] \
   && [ "$(cat "$REF_STAMP")" = "$FULL" ] \
   && [ "$(shasum -a 256 "$OUT" | cut -d' ' -f1)" = "$(cat "$HASH_STAMP")" ]; then
  log "Free ZIP up-to-date for $SHA: $OUT"
  CACHED=1
fi

if [ "$CACHED" -eq 0 ]; then
  rm -rf "$BUILD"
  mkdir -p "$BUILD/raw" "$BUILD/stage/wp-slimstat"
  git -C "$PLUGIN_SRC" archive --format=tar "$FULL" | tar -x -C "$BUILD/raw"
else
  mkdir -p "$BUILD"
fi
git -C "$PLUGIN_SRC" show "$FULL:.distignore" > "$DISTIGNORE" \
  || { err "Free $SHA has no exported .distignore"; exit 1; }

if [ "$CACHED" -eq 0 ]; then
  rsync -a --exclude-from="$DISTIGNORE" "$BUILD/raw/" "$BUILD/stage/wp-slimstat/"

  VERSION=$(sed -n 's/^ \* Version: *//p' "$BUILD/raw/wp-slimstat.php" | tr -d ' \r')
  [ -n "$VERSION" ] || { err "cannot read Free version at $SHA"; exit 1; }
  rm -f "$OUT"
  ( cd "$BUILD/stage" && zip -qr -X -9 "$OUT" wp-slimstat )
  printf '%s' "$FULL" > "$REF_STAMP"
  shasum -a 256 "$OUT" | cut -d' ' -f1 > "$HASH_STAMP"
else
  VERSION=$(unzip -p "$OUT" wp-slimstat/wp-slimstat.php \
    | sed -n 's/^ \* Version: *//p' | tr -d ' \r')
fi

LIST="$BUILD/list.txt"
unzip -Z1 "$OUT" | grep -v '/$' > "$LIST"
[ "$(cut -d/ -f1 "$LIST" | sort -u)" = wp-slimstat ] || { err "Free ZIP root is not wp-slimstat/"; exit 1; }
for required in wp-slimstat/wp-slimstat.php wp-slimstat/uninstall.php wp-slimstat/readme.txt \
                wp-slimstat/vendor/autoload.php wp-slimstat/vendor/composer/autoload_classmap.php; do
  grep -qxF "$required" "$LIST" || { err "Free ZIP is missing $required"; exit 1; }
done
while IFS= read -r pattern; do
  case "$pattern" in ''|'#'*) continue ;; esac
  if ! awk -v pattern="$pattern" '
    function leaked(path, target, anchored) {
      if (anchored) return path == target || index(path, target "/") == 1
      return path == target || index(path, "/" target "/") > 0 \
        || (length(path) > length(target) \
          && substr(path, length(path) - length(target)) == "/" target)
    }
    BEGIN {
      anchored = substr(pattern, 1, 1) == "/"
      if (anchored) pattern = substr(pattern, 2)
      target = anchored ? "wp-slimstat/" pattern : pattern
    }
    leaked($0, target, anchored) { exit 1 }
  ' "$LIST"; then
    err ".distignore entry '$pattern' leaked into Free ZIP"
    exit 1
  fi
done < "$DISTIGNORE"
unzip -tqq "$OUT" || { err "Free ZIP failed its CRC check"; exit 1; }
[ "$(unzip -p "$OUT" wp-slimstat/vendor/composer/autoload_real.php | grep -c setClassMapAuthoritative)" -eq 0 ] \
  || { err "Free ZIP autoloader is classmap-authoritative"; exit 1; }

log "Free ZIP ready: $OUT (v$VERSION @ $SHA, $(wc -l < "$LIST" | tr -d ' ') files)"
