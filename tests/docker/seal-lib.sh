#!/usr/bin/env bash
# Blind-seal primitives. seal.sh is the public CLI; entry points source this file.

: "${SLIMSTAT_SEAL_TEST_OWNER:=}"
: "${SLIMSTAT_SEAL_ENTROPY_SOURCE:=}"
: "${SLIMSTAT_SEAL_DRYRUN:=0}"
: "${PLUGIN_SRC:=}"

seal_err() { printf '%s\n' "$*" >&2; }
seal_sha256_text() { printf '%s' "$1" | shasum -a 256 | awk '{print $1}'; }
seal_file_mode() {
  local v
  if v=$(stat -f '%Lp' "$1" 2>/dev/null) && [ -n "$v" ]; then echo "$v"; return; fi
  if v=$(stat -c '%a' "$1" 2>/dev/null) && [ -n "$v" ]; then echo "$v"; return; fi
  return 1
}
seal_file_owner() {
  local v
  if v=$(stat -f '%u' "$1" 2>/dev/null) && [ -n "$v" ]; then echo "$v"; return; fi
  if v=$(stat -c '%u' "$1" 2>/dev/null) && [ -n "$v" ]; then echo "$v"; return; fi
  return 1
}
seal_assert_private() {
  local path="$1" want="$2" mode owner expected="$SLIMSTAT_SEAL_TEST_OWNER"
  [ -n "$expected" ] || expected=$(id -u)
  mode=$(seal_file_mode "$path") || { rm -f "$path"; seal_err "SEAL REFUSED: cannot read the mode of $path on this platform"; return 3; }
  [ "$mode" = "$want" ] || { rm -f "$path"; seal_err "SEAL REFUSED: $path is mode $mode, required $want"; return 3; }
  owner=$(seal_file_owner "$path") || { rm -f "$path"; seal_err "SEAL REFUSED: cannot read the owner of $path on this platform"; return 3; }
  [ "$owner" = "$expected" ] || { rm -f "$path"; seal_err "SEAL REFUSED: $path is owned by uid $owner, not by uid $expected"; return 3; }
}
seal_draw_entropy() {
  local source="$SLIMSTAT_SEAL_ENTROPY_SOURCE" hex length
  [ -n "$source" ] || source=/dev/urandom
  if [ -n "$SLIMSTAT_SEAL_ENTROPY_SOURCE" ] && [ "$SLIMSTAT_SEAL_DRYRUN" != 1 ]; then
    seal_err "SEAL REFUSED: SLIMSTAT_SEAL_ENTROPY_SOURCE is set outside a dry run"; return 3
  fi
  [ -r "$source" ] || { seal_err "SEAL REFUSED: entropy source $source is not readable"; return 3; }
  hex=$(dd if="$source" bs=1 count=32 2>/dev/null | od -An -tx1 | tr -d ' \n')
  length=$(printf '%s' "$hex" | wc -c | tr -d ' ')
  [ "$length" -eq 64 ] || { seal_err "SEAL REFUSED: entropy source $source yielded $length hex chars, expected 64"; return 3; }
  echo "$hex"
}
seal_validate_entropy() {
  local length
  length=$(printf '%s' "$1" | wc -c | tr -d ' ')
  [[ "$1" =~ ^[0-9a-f]+$ ]] && [ "$length" -eq 64 ] ||
    { seal_err "SEAL REFUSED: ENTROPY_V1 requires 32 bytes, got $((length/2))"; return 3; }
}
seal_json_get() {
  python3 - "$1" "$2" <<'PY'
import json,sys
v=json.load(open(sys.argv[1]))
for p in sys.argv[2].split('.'): v=v[p]
print('true' if v is True else 'false' if v is False else '' if v is None else v)
PY
}
seal_new_run_dir() {
  local root="$1" entropy suffix id dir
  mkdir -p "$root" || return 3
  for _ in 1 2 3; do
    entropy=$(seal_draw_entropy) || return 3; suffix=$(printf '%s' "$entropy" | cut -c1-6)
    id="R$(date -u +%Y%m%d)-$suffix"; dir="$root/$id"
    if mkdir "$dir" 2>/dev/null; then echo "$dir"; return; fi
  done
  seal_err "SEAL REFUSED: could not allocate a unique neutral RUN_ID after 3 draws"; return 3
}
seal_assert_neutral_names() {
  local root="$1" path name lower token repo
  while IFS= read -r path; do
    name=$(printf '%s' "$path" | sed "s#^$root/##"); lower=$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]')
    if printf '%s\n' "$lower" | grep -Eq '(^|[^a-z])(before|after|old|new|pre|post|baseline|head|dev|development|main|master|feat|origin)([^a-z]|$)|-to-|_to_|(^|[^a-z])v(5|6)([^0-9]|$)|(^|[^0-9])(5\.5|6\.0|2\.0|3\.0)([^0-9]|$)'; then
      seal_err "SEAL REFUSED: name '$name' contains a direction, branch, or version marker"; return 3
    fi
    # A raw SHA is just as identifying as "before". Resolve candidate tokens rather than
    # rejecting arbitrary hexadecimal data that happens to resemble one.
    for token in $(printf '%s\n' "$name" | grep -Eo '[0-9a-fA-F]{7,40}' || true); do
      for repo in "$PLUGIN_SRC" "$PLUGIN_SRC/../wp-slimstat-pro"; do
        [ -d "$repo/.git" ] || continue
        if git -C "$repo" rev-parse --verify --quiet "${token}^{commit}" >/dev/null; then
          seal_err "SEAL REFUSED: name '$name' contains git ref '$token'"; return 3
        fi
      done
    done
  done < <(find "$root" -mindepth 1 -print | LC_ALL=C sort)
}
seal_flip() {
  local dir="$1" a="$2" z="$3" rows="$4" days="$5" null="$6"
  local entropy commitment first bit arm1 arm2 id now source
  id=$(basename "$dir")
  [[ "$id" =~ ^R[0-9]{8}-[0-9a-f]{6}$ ]] || { seal_err "SEAL REFUSED: run id '$id' is not neutral"; return 3; }
  # The invariant belongs here, where the label is written, rather than at the three places that
  # each re-derived it. A run flagged null with two different refs used to seal cleanly and then
  # refuse much later in build-packet.py with the WRONG diagnosis -- "a null control's arms must
  # share every identity field", when the fault is a mislabelled run.
  case "$null" in
    1|true|True)
      [ "$a" = "$z" ] || { seal_err "SEAL REFUSED: a null control names two different refs"; return 3; } ;;
    *)
      [ "$a" != "$z" ] || { seal_err "SEAL REFUSED: one ref as both arms is a null control; pass null=1 to say so"; return 3; } ;;
  esac
  entropy=$(seal_draw_entropy) || return 3; seal_validate_entropy "$entropy" || return 3
  commitment=$(seal_sha256_text "$entropy"); first=$(printf '%s' "$entropy" | cut -c1-2); bit=$((16#$first & 1))
  if [ "$bit" -eq 0 ]; then arm1="$a"; arm2="$z"; else arm1="$z"; arm2="$a"; fi
  now=$(date -u +%FT%TZ); source=/dev/urandom; [ -n "$SLIMSTAT_SEAL_ENTROPY_SOURCE" ] && source=test-fixture
  mkdir -p "$dir/.sealed" "$dir/packet/arm-1" "$dir/packet/arm-2" "$dir/adjudication" "$dir/timing" || return 3
  chmod 700 "$dir/.sealed" || return 3; umask 077
  python3 - "$dir/.sealed/mapping.json" "$id" "$entropy" "$bit" "$arm1" "$arm2" "$a" "$z" "$rows" "$days" "$null" <<'PY'
import json,sys
p,r,e,b,x,y,a,z,rows,days,n=sys.argv[1:]
json.dump({'run_id':r,'entropy_hex':e,'b':int(b),'arm-1':x,'arm-2':y,'ref_a':a,'ref_b':z,'rows':int(rows),'days':int(days),'null_control':n in ('1','true','True')},open(p,'w'),sort_keys=True,separators=(',',':'))
open(p,'a').write('\n')
PY
  chmod 600 "$dir/.sealed/mapping.json"; umask 022
  python3 - "$dir/flip.json" "$id" "$commitment" "$now" "$source" "$null" <<'PY'
import json,sys
p,r,c,t,s,n=sys.argv[1:]
json.dump({'run_id':r,'algorithm':'ENTROPY_V1','source':s,'bytes':32,'commitment':c,'drawn_at':t,'null_control':n in ('1','true','True')},open(p,'w'),sort_keys=True,separators=(',',':'))
open(p,'a').write('\n')
PY
  chmod 644 "$dir/flip.json"
  seal_assert_private "$dir/.sealed" 700 &&
    seal_assert_private "$dir/.sealed/mapping.json" 600 &&
    seal_assert_neutral_names "$dir"
}
# seal_arm_manifest_digest() was here. It had ZERO callers and it was not the same rule as the
# three Python spellings it sat beside: it anchored the match to awk field 2 (`$2 ~ "^packet/arm-1/"`)
# where every Python copy tests substring-anywhere-in-line. An orphan fourth definition of the
# digest that decides whether a filed report matches the bytes it read, already disagreeing with
# the other three — deleted rather than converted, because a shell caller would have to shell out
# to the shared module anyway. The one definition is tests/docker/seal_common.py.
seal_refuse() { seal_err "SEAL REFUSED: P$1 unmet — $2"; return 4; }
